<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Events\FinalProjectHandedIn;
use App\Exceptions\FileException;
use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\SubmitFinalProjectRequest;
use App\Models\Evaluation;
use App\Models\FinalProject;
use App\Models\FinalProjectField;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Presenters\Participant\EvaluationPresenter;
use App\Presenters\Participant\FinalProjectPresenter;
use App\Presenters\Participant\HandInForm;
use App\Presenters\Participant\SubmissionPresenter;
use App\Presenters\Shared\HandInReceipt;
use App\Presenters\Support\HandInRules;
use App\Services\FinalProject\ReceiptCodes;
use App\Services\FinalProject\SubmissionFields;
use App\Services\Notifications\CohortNotices;
use App\Services\Storage\PrivateFileService;
use App\Services\Time\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The final project (PRD §9.14).
 *
 * BR-16, stated as a contract with the template: while the project is locked
 * this controller passes `$project = null` and `$isUnlocked = false`. Not one
 * word of the brief — title, description, criteria, attachments, deadline,
 * the hand-in fields — reaches the response, so viewing the page source before
 * the unlock reveals nothing. Asking for it directly is answered 403 by the
 * policy, not by hidden markup.
 *
 * D-121 — the hand-in form is the project's own list of fields, and a hand-in
 * is stored as one entry per field (SubmissionFields::answer()), each carrying
 * a copy of the field's label and type beside what came in.
 *
 * @see BR-15, BR-16, BR-19, BR-22 · FR-PROJ-10, FR-PROJ-11 · PRD §9.14 · CONSTITUTION Art. 5 · D-110, D-121
 */
final class FinalProjectController extends Controller
{
    public function __construct(
        private readonly PrivateFileService $files,
        private readonly CohortNotices $notices,
        private readonly ReceiptCodes $receipts,
    ) {}

    use ResolvesActiveCohort;

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $cohort = $this->activeCohort($user);
        $project = $cohort?->finalProject()->first();
        $now = Clock::now();

        $unlocked = $project instanceof FinalProject
            && (bool) $project->getAttribute('is_unlocked')
            && $user->can('view', $project);

        if (! $unlocked) {
            return view('participant.final-project', [
                'isUnlocked' => false,
                'project' => null,
                'handIn' => null,
                'receipt' => null,
                'submission' => null,
                'evaluation' => null,
                'canSubmit' => false,
                'closedReason' => null,
                'expectedOpeningLabel' => null,
                'serverNow' => $now,
                'errorState' => null,
            ]);
        }

        /** @var FinalProject $project */
        $submission = ProjectSubmission::query()
            ->with('latestEvaluation.evaluator.profile')
            ->where('final_project_id', $project->getKey())
            ->where('user_id', $user->getKey())
            ->orderByDesc('version')
            ->first();

        $isPastDue = $project->isPastDueAt($now);
        $allowsLate = (bool) $project->getAttribute('allow_late');
        // D-110's own switch: past the deadline blocks submission only when
        // the project forbids late hand-ins; when it allows them the form
        // stays open and the record is simply marked late (mirrors
        // Assignment::scopeOpenFor()'s exact rule).
        $canSubmit = $user->can('submit', $project) && (! $isPastDue || $allowsLate);

        $evaluation = $submission?->latestEvaluation;

        return view('participant.final-project', [
            'isUnlocked' => true,
            'project' => FinalProjectPresenter::from($project),
            'handIn' => HandInForm::from($project->fields()->get(), $submission),
            // D-122 — the receipt of the newest version, when it carries one.
            'receipt' => $submission instanceof ProjectSubmission && filled($submission->getAttribute('receipt_code'))
                ? HandInReceipt::from($submission, $project, true)
                : null,
            'submission' => $submission === null
                ? null
                : SubmissionPresenter::fromProject(
                    $submission,
                    $evaluation instanceof Evaluation ? $evaluation : null,
                    (float) $project->getAttribute('max_score'),
                ),
            'evaluation' => $evaluation instanceof Evaluation
                ? EvaluationPresenter::from($evaluation)
                : null,
            'canSubmit' => $canSubmit,
            // A closed submission area always says why (PRD §9.9.4's rule).
            'closedReason' => $canSubmit
                ? null
                : (string) __($isPastDue ? 'project.closed_deadline' : 'project.closed_locked'),
            'expectedOpeningLabel' => null,
            'serverNow' => $now,
            'errorState' => null,
        ]);
    }

    /**
     * Hand in the project. Like an assignment, a submission is a new version
     * and never overwrites the previous one (BR-19). Every field is answered in
     * the order the form showed it; an upload field's files are stored under
     * the field's own formats, checked on the bytes (art. 24).
     */
    public function submit(SubmitFinalProjectRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = $request->finalProject();

        if (! $project instanceof FinalProject) {
            return back()->withErrors(['answers' => __('project.errors.not_available')]);
        }

        $this->authorize('submit', $project);

        $now = Clock::now();
        $dueAt = $project->getAttribute('due_at');

        // D-110's late-submission switch: past the deadline is refused
        // outright when the project forbids it, exactly like an assignment
        // that does not allow_late (BR-18's own rule, mirrored).
        if ($project->isPastDueAt($now) && ! (bool) $project->getAttribute('allow_late')) {
            return back()->withErrors(['answers' => __('project.closed_deadline')]);
        }

        $previous = (int) ProjectSubmission::query()
            ->where('final_project_id', $project->getKey())
            ->where('user_id', $user->getKey())
            ->max('version');

        // Every file is stored and the row written in ONE transaction (D-56),
        // so no hand-in claims a file it does not have; and when anything
        // fails, the files already written for this attempt are removed from
        // the disk again, so none is left with no row pointing at it. A refused
        // file names the field it came through. The transaction is opened by
        // hand rather than through a closure so the field being stored stays
        // visible to the refusal below.
        $failedField = null;
        $written = [];

        DB::beginTransaction();

        try {
            $answers = [];

            foreach ($request->fields() as $field) {
                $type = $field->fieldType();
                $label = (string) $field->getAttribute('label');

                if (! $type->isFile()) {
                    $answers[] = SubmissionFields::answer((string) $field->getKey(), $type, $label, $request->valueFor($field));

                    continue;
                }

                $failedField = $field;
                $stored = [];

                foreach ($request->filesFor($field) as $file) {
                    $stored[] = $written[] = $this->files->store(
                        $file,
                        'final-projects/'.$project->getKey(),
                        $user,
                        $field->acceptedMimeTypes(),
                    );
                }

                $failedField = null;
                $answers[] = SubmissionFields::answer((string) $field->getKey(), $type, $label, null, $stored);
            }

            $submission = ProjectSubmission::query()->create([
                'final_project_id' => $project->getKey(),
                'user_id' => $user->getKey(),
                'answers' => $answers,
                'submitted_at' => $now,
                'is_late' => $dueAt !== null && $now->greaterThan(Clock::toUtc($dueAt)),
                'version' => $previous + 1,
                // D-122 — the code the receipt letter, page and QR carry.
                'receipt_code' => $this->receipts->unused(),
            ]);

            DB::commit();
        } catch (\Throwable $failure) {
            DB::rollBack();
            $this->discard($written, $user);

            if (! $failure instanceof FileException) {
                throw $failure;
            }

            if (! $failedField instanceof FinalProjectField) {
                return back()->withErrors(['answers' => $failure->localizedMessage()]);
            }

            // A file whose BYTES are not one of the field's formats is named
            // with the field and its formats; any other refusal keeps the
            // service's own words. localizedMessage(), not getMessage() — the
            // latter is the raw translation key, kept language neutral for
            // the log (see DomainException).
            return back()->withErrors([
                SubmitFinalProjectRequest::key($failedField) => $failure->langKey() === 'errors.file.mime_not_allowed'
                    ? (string) __('project.errors.field_file_type', [
                        'field' => (string) $failedField->getAttribute('label'),
                        'formats' => HandInRules::formats($failedField),
                    ])
                    : $failure->localizedMessage(),
            ]);
        }

        // The same two notices an assignment hand-in sends (D-83), the
        // participant's naming the receipt code and the next step (D-122) —
        // and the receipt letter with its QR, written by the queue.
        $cohortId = (string) $project->getAttribute('cohort_id');
        $receiptCode = (string) $submission->getAttribute('receipt_code');

        $this->notices->projectHandedIn(
            $user,
            $cohortId,
            (string) $project->getAttribute('title'),
            $receiptCode,
            route('finalProject.receipt', ['code' => $receiptCode]),
            route('trainer.finalProject', ['cohort' => $cohortId]),
        );

        try {
            FinalProjectHandedIn::dispatch((string) $submission->getKey());
        } catch (\Throwable $failure) {
            // The hand-in is saved; a letter that cannot be queued must not
            // turn that success into an error page and a second hand-in.
            Log::error('events.final_project_handed_in_failed', ['exception' => $failure::class]);
        }

        return redirect()
            ->route('finalProject')
            ->with('status', __('project.submitted'));
    }

    /**
     * Remove the files one failed attempt already wrote; the service records
     * each removal in audit_logs (art. 8). Best effort: a file that cannot be
     * removed is left where it is rather than turning a refused hand-in into
     * an error page.
     *
     * @param  list<array<string, mixed>>  $descriptors
     */
    private function discard(array $descriptors, User $user): void
    {
        foreach ($descriptors as $descriptor) {
            $path = $descriptor['path'] ?? null;
            $disk = $descriptor['disk'] ?? null;

            if (! is_string($path)) {
                continue;
            }

            try {
                $this->files->delete($path, $user, is_string($disk) ? $disk : null);
            } catch (FileException) {
                // The path came from the service itself; nothing else to do.
            }
        }
    }
}
