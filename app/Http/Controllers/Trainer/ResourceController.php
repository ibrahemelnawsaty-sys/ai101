<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Enums\ResourceType;
use App\Exceptions\FileException;
use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trainer\StoreResourceRequest;
use App\Http\Requests\Trainer\UpdateResourceRequest;
use App\Models\Cohort;
use App\Models\Resource;
use App\Models\Session;
use App\Models\User;
use App\Models\Week;
use App\Presenters\Support\Options;
use App\Presenters\Trainer\ResourceEditForm;
use App\Presenters\Trainer\ResourceRow;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\CohortNotices;
use App\Services\Storage\PrivateFileService;
use App\Services\Time\Clock;
use App\Support\ListFilter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The training kit as the trainer manages it (PRD §9.12).
 *
 * Uploaded files are written outside the web root with a random name, and their
 * type is sniffed from the file's own bytes rather than trusted from the
 * extension — that work belongs to App\Services\Storage and is not part of this
 * slice; a link resource needs none of it and works today.
 *
 * Download counts are visible here and nowhere else (PRD §9.12).
 *
 * The table is handed ResourceRow presenters rather than Resource models: the
 * screen reads `typeLabel`, `stateVariant` and `weekTitle`, none of which a
 * model carries, and all of which are presentation decisions taken on the
 * server (art. 5).
 *
 * @see BR-23 · FR-RES-08, FR-RES-10 · PRD §9.12, §12.5 · CONSTITUTION art. 5, art. 8, art. 22, art. 24 · D-136
 */
final class ResourceController extends Controller
{
    use ReadsCohortScope;

    private const PER_PAGE = 50;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PrivateFileService $files,
        private readonly CohortNotices $notices,
    ) {}

    public function index(Request $request): View
    {
        $cohort = $this->scopedCohort($request);

        if ($cohort === null) {
            return view('trainer.resources', array_merge($this->uploadLimits(), [
                'contextLabel' => null,
                'resources' => collect(),
                'weekOptions' => [],
                'sessionOptions' => [],
                'typeOptions' => Options::fromEnum(ResourceType::class),
                'stateOptions' => $this->stateOptions(),
                'editing' => null,
                'closeHref' => null,
                'carriedQuery' => [],
                'errorState' => null,
            ]));
        }

        $weeks = Week::query()
            ->where('cohort_id', $cohort->getKey())
            ->orderBy('index')
            ->get();

        $sessions = Session::query()
            ->where('cohort_id', $cohort->getKey())
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $page = $this->query($request, $cohort)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('trainer.resources', array_merge($this->uploadLimits(), [
            'contextLabel' => $cohort->getAttribute('name'),
            'resources' => $page->through(
                static fn (Resource $row): ResourceRow => ResourceRow::from($row),
            ),
            'weekOptions' => Options::fromModels(
                $weeks,
                static fn (Week $item): string => (string) $item->getAttribute('title'),
            ),
            'sessionOptions' => Options::fromModels(
                $sessions,
                static fn (Session $item): string => (string) (
                    $item->getAttribute('topic') ?? $item->getAttribute('title')
                ),
            ),
            'typeOptions' => Options::fromEnum(ResourceType::class),
            'stateOptions' => $this->stateOptions(),
            // D-136 — the edit drawer, open on `?edit={id}` when that names an
            // item of THIS cohort; anything else opens nothing.
            'editing' => $this->editing($request, $cohort),
            // A PATH, not a URL: the drawer accepts a local path only (anything
            // else is dropped and the panel would merely hide, leaving `?edit=`
            // in the address).
            'closeHref' => route('trainer.resources', $this->carriedQuery($request, (string) $cohort->getKey()), false),
            'carriedQuery' => $this->carriedQuery($request, (string) $cohort->getKey()),
            'errorState' => null,
        ]));
    }

    /**
     * The item the edit drawer is open on. Asked inside the scoped cohort, so
     * an id from another cohort finds nothing and no drawer opens; the update
     * endpoint runs its own policy check regardless — a drawer that does not
     * open is not a permission (art. 5, art. 22). An archived item is
     * included: it may be corrected before it is restored.
     */
    private function editing(Request $request, Cohort $cohort): ?ResourceEditForm
    {
        $id = $request->query('edit');

        if (! is_string($id) || $id === '') {
            return null;
        }

        /** @var resource|null $resource */
        $resource = Resource::query()
            ->withTrashed()
            ->where('cohort_id', $cohort->getKey())
            ->whereKey($id)
            ->first();

        return $resource === null ? null : ResourceEditForm::from($resource);
    }

    /**
     * What the drawer's links and its form carry back: the cohort and the
     * list's own filters, as plain strings — so closing or saving returns to
     * the same list, not to an unfiltered one.
     *
     * @return array<string, string>
     */
    private function carriedQuery(Request $request, string $cohortId): array
    {
        $carried = ['cohort' => $cohortId];

        foreach (['q', 'state', 'week'] as $key) {
            $value = ListFilter::text($request, $key);

            if ($value !== null) {
                $carried[$key] = $value;
            }
        }

        return $carried;
    }

    /**
     * The kit listing, bound to the scoped cohort and narrowed by the toolbar.
     *
     * @return Builder<resource>
     */
    private function query(Request $request, Cohort $cohort): Builder
    {
        $query = Resource::query()
            ->with(['week', 'session'])
            ->where('cohort_id', $cohort->getKey())
            ->orderByDesc('created_at');

        $search = $request->query('q');

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.trim($search).'%';

            $query->where(static function (Builder $inner) use ($term): void {
                $inner->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        $week = $request->query('week');

        if (is_string($week) && $week !== '') {
            $query->where('week_id', $week);
        }

        // Archived material is hidden by default rather than deleted: the
        // trainer asks for it explicitly (art. 13 §11). Resource now carries
        // SoftDeletes, so the default query already excludes trashed rows on
        // its own; only the two non-default states need to say anything.
        $state = $request->query('state');

        if ($state === 'archived') {
            $query->onlyTrashed();
        } elseif ($state === 'all') {
            $query->withTrashed();
        }

        return $query;
    }

    /**
     * The three states the toolbar filters by. Labels come from lang/, never
     * from a string in this file (art. 15).
     *
     * @return list<array{value: string, label: string}>
     */
    private function stateOptions(): array
    {
        return Options::fromPairs([
            'active' => (string) __('trainer.resources.state_active'),
            'archived' => (string) __('trainer.resources.state_archived'),
            'all' => (string) __('app.all'),
        ]);
    }

    /**
     * The upload ceilings, read from config/athar.php so no screen invents its
     * own limit and the server's own check reads the same numbers (BR-36).
     *
     * @return array<string, int|string>
     */
    private function uploadLimits(): array
    {
        $kilobytes = (int) config('athar.uploads.max_kilobytes', 25600);
        $extensions = (array) config('athar.uploads.allowed_extensions', []);

        return [
            'maxFiles' => (int) config('athar.uploads.max_files', 5),
            'maxFileBytes' => $kilobytes * 1024,
            'maxFileSizeLabel' => (string) (int) round($kilobytes / 1024),
            'uploadAccept' => $extensions === [] ? null : '.'.implode(',.', $extensions),
        ];
    }

    public function store(StoreResourceRequest $request): RedirectResponse
    {
        /** @var User $uploader */
        $uploader = $request->user();

        $columns = $request->columns();

        // The upload was validated and then THROWN AWAY: nothing here ever
        // touched $request->file('file'). The row was created with
        // `file_url = null`, the trainer read the success message, the bytes
        // were deleted with the temp file at the end of the request, and on
        // the participant side ResourcePresenter hides the download of any
        // file resource whose `file_url` is null. Teaching material, uploaded
        // and gone (D-65).
        //
        // Through PrivateFileService, the same path submissions take since
        // D-56: the MIME type is sniffed from the file's own bytes (PRD
        // §12.5), the name on disk is random, and it lives outside the web
        // root. Participants reach it only through the download route, which
        // checks the policy and reads `file_url` from the `private` disk --
        // the disk the service writes to.
        $file = $request->file('file');

        if ($file instanceof UploadedFile) {
            try {
                $stored = $this->files->store($file, 'resources/'.$columns['cohort_id'], $uploader);
            } catch (FileException $failure) {
                return back()->withErrors(['file' => $failure->localizedMessage()])->withInput();
            }

            $columns['file_url'] = $stored['path'];
            $columns['size'] = $stored['size_bytes'];
        }

        Resource::query()->create(array_merge($columns, [
            'uploaded_by' => $uploader->getKey(),
            'download_count' => 0,
        ]));

        // PRD §9.16.1: the whole cohort, on the platform (D-83).
        $this->notices->resourceAdded((string) $columns['cohort_id'], (string) $columns['title']);

        return back()->with('status', __('trainer.resources.created'));
    }

    /**
     * FR-RES-10 — correct an item's DATA (D-136): title, description, week,
     * session, and the address of a link or a video. The file is never touched
     * here, and neither are the type, the size, the download count, the
     * uploader or the cohort: UpdateResourceRequest does not read them.
     *
     * The edit is written to the audit trail with what it changed, before it is
     * saved (art. 8). It tells nobody: the cohort was told when the material was
     * added (FR-RES-09), and a corrected title is not news.
     */
    public function update(UpdateResourceRequest $request, Resource $resource): RedirectResponse
    {
        $resource->fill($request->columns());

        $before = [];
        $after = [];

        foreach ($resource->getDirty() as $column => $value) {
            $before[$column] = $resource->getOriginal($column);
            $after[$column] = $value;
        }

        if ($after !== []) {
            $this->audit->log('resource.updated', $resource, $before, $after);
            $resource->save();
        }

        // Back to the list the drawer was opened from — closing it.
        return redirect()
            ->route('trainer.resources', $this->carriedQuery($request, (string) $resource->getAttribute('cohort_id')))
            ->with('status', __('trainer.resources.updated'));
    }

    /**
     * Archiving hides a resource without destroying it. Resource now carries
     * SoftDeletes — the route is declared `->withTrashed()` so an already
     * archived resource can still be found and restored — but the stamp
     * itself is still written by hand with Clock::now() rather than by
     * calling delete()/restore(): those write Eloquent's own timestamp, which
     * does not honour Clock::fake() in tests and would stop being the one
     * clock the platform reads (art. 11, gate G4).
     *
     * The same endpoint restores: a resource that is already archived has its
     * stamp cleared, which is what the button on the row offers to do.
     */
    public function archive(Resource $resource): RedirectResponse
    {
        $this->authorize('delete', $resource);

        $previous = $resource->getAttribute('deleted_at');
        $isArchived = $previous !== null;
        $stamp = $isArchived ? null : Clock::now();

        $this->audit->log(
            $isArchived ? 'resource.restored' : 'resource.archived',
            $resource,
            ['deleted_at' => $previous === null ? null : (string) $previous],
            ['deleted_at' => $stamp?->toIso8601String()],
        );

        $resource->forceFill(['deleted_at' => $stamp])->save();

        return back()->with('status', __(
            $isArchived ? 'trainer.resources.restored' : 'trainer.resources.archived',
        ));
    }
}
