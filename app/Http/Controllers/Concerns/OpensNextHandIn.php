<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\EvaluationEntity;
use App\Models\Assignment;
use App\Models\Evaluation;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\Submission;
use App\Services\Grading\GradingQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where the trainer lands after «save and go to the next» — used by the two
 * grading screens and by the amendment endpoint they share.
 *
 * The destination is built from what the SERVER knows: the cohort and the
 * assignment come from the hand-in itself, never from the request, and the
 * only things carried over from the trainer's own view are two board filters
 * (`status`, `q`), taken as plain strings. Nothing in the request can name
 * another route or another host, so the flag cannot become an open redirect
 * (art. 22, art. 24).
 *
 * The user using this declares `private readonly GradingQueue $queue`.
 *
 * @see BR-19, BR-23 · FR-ASGN-29 · D-136
 */
trait OpensNextHandIn
{
    /** The board filters a trainer's own view carries across the hop. */
    private const CARRIED_FILTERS = ['status', 'q'];

    /** The assignments board, on the oldest waiting hand-in of the same assignment. */
    protected function boardAfter(Request $request, Submission $current, string $message): RedirectResponse
    {
        $assignmentId = (string) $current->getAttribute('assignment_id');
        $cohortId = Assignment::query()->whereKey($assignmentId)->value('cohort_id');

        $params = ['cohort' => $cohortId, 'assignment' => $assignmentId] + $this->carriedFilters($request);
        $next = $this->queue->nextAssignmentSubmission($current);

        if ($next === null) {
            return redirect()->route('trainer.submissions', $params)
                ->with('status', $message.' '.__('trainer.grading.queue_done'));
        }

        return redirect()->route('trainer.submissions', $params + ['submission' => $next->getKey()])
            ->with('status', $message);
    }

    /** The final-project screen, on the oldest waiting hand-in of the same project. */
    protected function projectAfter(ProjectSubmission $current, string $message): RedirectResponse
    {
        $cohortId = FinalProject::query()
            ->whereKey($current->getAttribute('final_project_id'))
            ->value('cohort_id');

        $next = $this->queue->nextProjectSubmission($current);

        if ($next === null) {
            return redirect()->route('trainer.finalProject', ['cohort' => $cohortId])
                ->with('status', $message.' '.__('trainer.grading.queue_done'));
        }

        return redirect()->route('trainer.finalProject', ['cohort' => $cohortId, 'grade' => $next->getKey()])
            ->with('status', $message);
    }

    /**
     * After an amendment: back to whichever screen the amended mark belongs to.
     * A mark whose hand-in cannot be found simply stays where it was.
     */
    protected function nextAfterRevision(Request $request, Evaluation $evaluation, string $message): RedirectResponse
    {
        $entityId = (string) $evaluation->getAttribute('entity_id');

        if ($evaluation->entity_type === EvaluationEntity::FinalProject) {
            $current = ProjectSubmission::query()->find($entityId);

            return $current instanceof ProjectSubmission
                ? $this->projectAfter($current, $message)
                : back()->with('status', $message);
        }

        $current = Submission::query()->find($entityId);

        return $current instanceof Submission
            ? $this->boardAfter($request, $current, $message)
            : back()->with('status', $message);
    }

    /**
     * @return array<string, string>
     */
    private function carriedFilters(Request $request): array
    {
        $carried = [];

        foreach (self::CARRIED_FILTERS as $key) {
            $value = $request->query($key);

            if (is_string($value) && $value !== '') {
                $carried[$key] = $value;
            }
        }

        return $carried;
    }
}
