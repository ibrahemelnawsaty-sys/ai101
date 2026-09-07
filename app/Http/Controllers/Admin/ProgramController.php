<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ProgramStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProgramRequest;
use App\Http\Requests\Admin\UpdateProgramRequest;
use App\Models\Program;
use App\Presenters\Admin\ArchiveTarget;
use App\Presenters\Admin\ProgramForm;
use App\Presenters\Admin\ProgramRow;
use App\Presenters\Support\Options;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Programmes (PRD §4.2, §9.18).
 *
 * The whole point of this screen is BR-31: the objectives, the audience, the
 * certificates and the description a visitor reads are rows in a table that the
 * centre edits, not strings in a template.
 *
 * @see BR-31, BR-36 · PRD §4.2, §7.2, §9.18 · CONSTITUTION Art. 6, Art. 8
 */
final class ProgramController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Program::class);

        $query = Program::query()->withCount('cohorts')->orderByDesc('created_at');

        $search = $request->query('q');

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('name_ar', 'like', $term)
                    ->orWhere('name_en', 'like', $term)
                    ->orWhere('slug', 'like', $term);
            });
        }

        $status = $request->query('status');

        if (is_string($status) && ProgramStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        return view('admin.programs', [
            'contextLabel' => null,
            'programs' => $query->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(static fn (Program $program): ProgramRow => ProgramRow::from($program)),
            'statusOptions' => Options::fromEnum(ProgramStatus::class),
            'editing' => $this->editing($request),
            'archiving' => $this->archiving($request),
            'errorState' => null,
        ]);
    }

    /** The editor panel, open on `?edit=new` or on `?edit={id}`. */
    private function editing(Request $request): ?ProgramForm
    {
        $edit = $request->query('edit');

        if (! is_string($edit) || $edit === '') {
            return null;
        }

        if ($edit === 'new') {
            return ProgramForm::blank();
        }

        /** @var Program|null $program */
        $program = Program::query()->find($edit);

        return $program === null ? null : ProgramForm::from($program);
    }

    /** The archive confirmation, open on `?archive={id}`. */
    private function archiving(Request $request): ?ArchiveTarget
    {
        $id = $request->query('archive');

        if (! is_string($id) || $id === '') {
            return null;
        }

        /** @var Program|null $program */
        $program = Program::query()->find($id);

        return $program === null ? null : ArchiveTarget::from($program);
    }

    public function store(StoreProgramRequest $request): RedirectResponse
    {
        /** @var Program $program */
        $program = Program::query()->create($request->columns());

        $this->audit->log('program.created', $program, null, ['slug' => (string) $program->getAttribute('slug')]);

        return redirect()
            ->route('admin.programs.index')
            ->with('status', __('admin.programs.created'));
    }

    public function update(UpdateProgramRequest $request, Program $program): RedirectResponse
    {
        $before = $this->audit->snapshot($program, ['slug', 'status']);

        $program->fill($request->columns());

        $this->audit->log(
            action: 'program.updated',
            entity: $program,
            before: $before,
            after: $this->audit->snapshot($program, ['slug', 'status']),
        );

        $program->save();

        return back()->with('status', __('admin.programs.updated'));
    }

    /**
     * Archiving takes a programme out of circulation without destroying it or
     * its cohorts: the status becomes `archived` and everything already
     * attached to it — enrolments, attendance, certificates — stays exactly
     * where it is (PRD §7.8).
     */
    public function archive(Program $program): RedirectResponse
    {
        $this->authorize('archive', $program);

        $before = $this->audit->snapshot($program, ['status']);

        $program->setAttribute('status', ProgramStatus::Archived->value);

        $this->audit->log(
            action: 'program.archived',
            entity: $program,
            before: $before,
            after: $this->audit->snapshot($program, ['status']),
        );

        $program->save();

        return back()->with('status', __('admin.programs.archived'));
    }
}
