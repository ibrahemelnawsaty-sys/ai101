<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfirmUserImportRequest;
use App\Http\Requests\Admin\ImportUsersRequest;
use App\Jobs\InviteImportedParticipant;
use App\Models\Cohort;
use App\Models\User;
use App\Presenters\Support\Options;
use App\Services\Audit\AuditLogger;
use App\Services\Import\ParticipantImportReader;
use App\Services\Import\ParticipantImportSheet;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Bringing a whole cohort in from one sheet.
 *
 * THE SHAPE: download → fill → upload → SEE WHAT WILL HAPPEN → confirm.
 * The preview is not a nicety. Sixty rows typed by hand will contain a
 * duplicate address and a phone number with a space in it, and an import that
 * discovers that halfway through leaves half a cohort created and no way to
 * tell which half. Nothing is written until the administrator has seen the
 * table and pressed the second button.
 *
 * NOTHING IS CREATED IN THE REQUEST. Confirm pushes one queued job per valid
 * row and answers immediately. Each account costs a bcrypt hash at twelve
 * rounds — a quarter of a second, deliberately — so two hundred rows would be
 * fifty seconds of hashing alone, and shared hosting kills a long request
 * rather than letting it finish.
 *
 * THE SHEET IS KEPT, BRIEFLY, OUTSIDE THE WEB ROOT. The preview and the confirm
 * are two requests, and the second has to read the same bytes the first
 * validated. It lives on the private disk under a random name, is deleted the
 * moment it has been used, and its path is held in the session — not in a form
 * field, where a different administrator could point the confirm at somebody
 * else's upload.
 *
 * VALIDATED TWICE, ON PURPOSE. The world moves between the two requests: an
 * address that was free during the preview can be taken by the time the button
 * is pressed, by the single-account form or by the other half of a
 * double-click.
 *
 * @see PRD §4.2, §4.5.1, §12.5 · CONSTITUTION Art. 5, Art. 10 · D-63
 */
final class UserImportController extends Controller
{
    use ExportsCsv;

    /** Where an upload waits between the preview and the confirm. */
    private const SESSION_KEY = 'admin.import.sheet';

    private const DISK = 'private';

    private const FOLDER = 'imports';

    public function __construct(
        private readonly ParticipantImportReader $reader,
        private readonly AuditLogger $audit,
    ) {}

    /** The upload screen. */
    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.import', [
            'contextLabel' => null,
            'cohortOptions' => $this->cohortOptions(),
            'rows' => null,
            'validCount' => 0,
            'rejectedCount' => 0,
            'cohortId' => null,
            'maxRows' => $this->maxRows(),
            'errorState' => null,
        ]);
    }

    /**
     * The .xlsx template.
     *
     * An ASCII filename on purpose: `Content-Disposition` here interpolates the
     * name raw, with no RFC 5987 `filename*=UTF-8''…`, so an Arabic name would
     * reach the browser mangled. The Arabic name of the thing belongs on the
     * link, which is a place that can hold it.
     */
    public function template(): Response
    {
        $this->authorize('create', User::class);

        return response((new ParticipantImportSheet)->build(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="athar-import-template.xlsx"',
        ]);
    }

    /**
     * The same columns as a CSV, for anyone who wants one.
     *
     * Its guidance says to write the mobile number in its `9665…` form. That is
     * not a preference: Excel strips the quotes from `"0512345678"` and then
     * type-infers, turning it into a nine-digit number that fails the platform's
     * phone rule on every single row. The `9665…` form has no leading zero to
     * lose, and `ProfileFieldRules::canonicalPhone()` maps it back.
     */
    public function templateCsv(): Response
    {
        $this->authorize('create', User::class);

        return $this->csvResponse(
            ParticipantImportSheet::headings(),
            [],
            'athar-import-template.csv',
        );
    }

    /** Read the sheet and show what would happen. Writes nothing. */
    public function preview(ImportUsersRequest $request): View|RedirectResponse
    {
        $file = $request->file('sheet');

        if (! $file instanceof \Illuminate\Http\UploadedFile) {
            return back()->withErrors(['sheet' => __('admin.users.import.errors.unreadable')]);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $stored = $file->storeAs(
            self::FOLDER,
            Str::uuid()->toString().'.'.($extension === '' ? 'xlsx' : $extension),
            self::DISK,
        );

        if (! is_string($stored) || $stored === '') {
            return back()->withErrors(['sheet' => __('admin.users.import.errors.unreadable')]);
        }

        try {
            $rows = $this->reader->read(Storage::disk(self::DISK)->path($stored), $extension);
        } catch (\Throwable) {
            Storage::disk(self::DISK)->delete($stored);

            return back()->withErrors(['sheet' => __('admin.users.import.errors.unreadable')]);
        }

        if ($rows === []) {
            Storage::disk(self::DISK)->delete($stored);

            return back()->withErrors(['sheet' => __('admin.users.import.errors.empty')]);
        }

        if (count($rows) > $this->maxRows()) {
            Storage::disk(self::DISK)->delete($stored);

            return back()->withErrors([
                'sheet' => __('admin.users.import.errors.too_many', ['max' => $this->maxRows()]),
            ]);
        }

        $request->session()->put(self::SESSION_KEY, [
            'path' => $stored,
            'extension' => $extension,
            'cohort_id' => $request->cohortId(),
        ]);

        $valid = count(array_filter($rows, static fn ($row): bool => $row->isValid()));

        return view('admin.users.import', [
            'contextLabel' => null,
            'cohortOptions' => $this->cohortOptions(),
            'rows' => $rows,
            'validCount' => $valid,
            'rejectedCount' => count($rows) - $valid,
            'cohortId' => $request->cohortId(),
            'maxRows' => $this->maxRows(),
            'errorState' => null,
        ]);
    }

    /**
     * Confirm: queue one invitation per valid row.
     *
     * The session key is PULLED, so a second press finds nothing and says so
     * rather than sending every letter twice. The jobs themselves refuse an
     * address that already has an account, which covers the case where the
     * first press succeeded and the browser retried on its own.
     */
    public function store(ConfirmUserImportRequest $request): RedirectResponse
    {
        // Authorised by ConfirmUserImportRequest. The policy check used to live
        // here, which left the endpoint without the FormRequest Article 5
        // requires on every state-changing route — on the one route that
        // queues up to two hundred accounts in a single press (D-66).

        /** @var array{path: string, extension: string, cohort_id: string}|null $pending */
        $pending = $request->session()->pull(self::SESSION_KEY);

        if ($pending === null) {
            return redirect()
                ->route('admin.users.import')
                ->withErrors(['sheet' => __('admin.users.import.errors.nothing_pending')]);
        }

        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($pending['path'])) {
            return redirect()
                ->route('admin.users.import')
                ->withErrors(['sheet' => __('admin.users.import.errors.nothing_pending')]);
        }

        $cohort = Cohort::query()->whereKey($pending['cohort_id'])->first();

        if (! $cohort instanceof Cohort) {
            $disk->delete($pending['path']);

            return redirect()
                ->route('admin.users.import')
                ->withErrors(['cohort_id' => __('admin.users.import.errors.cohort_missing')]);
        }

        // Read again rather than trusting the preview: between the two requests
        // an address can be taken by the single-account form or by the other
        // half of a double-click.
        $rows = $this->reader->read($disk->path($pending['path']), $pending['extension']);
        $disk->delete($pending['path']);

        $queued = 0;

        foreach ($rows as $row) {
            if (! $row->isValid()) {
                continue;
            }

            InviteImportedParticipant::dispatch(
                $row->email(),
                $row->profileColumns(),
                (string) $cohort->getKey(),
            );

            $queued++;
        }

        $this->audit->log('users.imported', $cohort, null, [
            'cohort_id' => (string) $cohort->getKey(),
            'queued' => $queued,
            'rejected' => count($rows) - $queued,
        ]);

        return redirect()
            ->route('admin.users.index')
            ->with('status', __('admin.users.import.queued', ['count' => $queued]));
    }

    /** @return array<int, array{value: string, label: string}> */
    private function cohortOptions(): array
    {
        return Options::fromModels(
            Cohort::query()->with('program')->orderByDesc('start_date')->get(),
            static fn (Cohort $cohort): string => (string) $cohort->getAttribute('name'),
        );
    }

    /** BR-36: the ceiling is configuration, never a literal here. */
    private function maxRows(): int
    {
        $max = (int) config('athar.invitations.import_max_rows', 200);

        return $max > 0 ? $max : 200;
    }
}
