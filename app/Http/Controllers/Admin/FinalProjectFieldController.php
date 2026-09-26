<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteFinalProjectFieldRequest;
use App\Http\Requests\Admin\MoveFinalProjectFieldRequest;
use App\Http\Requests\Admin\SaveFinalProjectFieldRequest;
use App\Models\FinalProject;
use App\Models\FinalProjectField;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * The hand-in fields of a final project, from the general supervisor's own
 * screen (D-121): add one, change one, move one up or down, remove one.
 *
 * Each write is recorded in audit_logs BEFORE it happens, with the field as it
 * was and as it becomes (art. 8). None of them touches a hand-in: a hand-in
 * keeps its own copy of the fields it answered, so the form can change the
 * night before the deadline without rewriting what already arrived (BR-19).
 *
 * Every route here scopes the field to the project in the URL (scoped
 * binding), so a field id pasted under another project's address answers 404
 * before any policy runs; the policy then answers 403 to anyone but an active
 * general supervisor outside a preview (BR-33).
 *
 * @see BR-19, BR-31, BR-33 · FR-PROJ-10 · PRD §9.14.2 · D-110, D-121 · CONSTITUTION Art. 5, Art. 8
 */
final class FinalProjectFieldController extends Controller
{
    /** The columns the trail records for a field. */
    private const AUDITED = [
        'type', 'label', 'description', 'tips', 'is_required',
        'accepted_formats', 'max_kilobytes', 'max_files', 'position',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(SaveFinalProjectFieldRequest $request, FinalProject $project): RedirectResponse
    {
        $field = new FinalProjectField($request->columns());
        $field->setAttribute('id', $field->newUniqueId());
        $field->setAttribute('final_project_id', $project->getKey());
        $field->setAttribute('position', $this->lastPosition($project) + 1);

        $this->audit->log(
            action: 'final_project_field.created',
            entity: $field,
            before: null,
            after: $this->audit->snapshot($field, self::AUDITED),
            actor: $this->actor($request->user()),
        );

        $field->save();

        return $this->backTo($project, (string) __('admin.final_project.submission_fields.created', [
            'label' => (string) $field->getAttribute('label'),
        ]));
    }

    public function update(SaveFinalProjectFieldRequest $request, FinalProject $project, FinalProjectField $field): RedirectResponse
    {
        $before = $this->audit->snapshot($field, self::AUDITED);

        $field->fill($request->columns());

        $this->audit->log(
            action: 'final_project_field.updated',
            entity: $field,
            before: $before,
            after: $this->audit->snapshot($field, self::AUDITED),
            actor: $this->actor($request->user()),
        );

        $field->save();

        return $this->backTo($project, (string) __('admin.final_project.submission_fields.updated', [
            'label' => (string) $field->getAttribute('label'),
        ]));
    }

    /**
     * One place up or down. The whole list is renumbered 1..n in the new
     * order, so gaps left by a removal and ties between two positions both
     * disappear on the first move. At either end the list is left as it is.
     */
    public function move(MoveFinalProjectFieldRequest $request, FinalProject $project, FinalProjectField $field): RedirectResponse
    {
        $ordered = $project->fields()->get()->values();
        $from = $ordered->search(static fn (FinalProjectField $row): bool => $row->is($field));
        $to = is_int($from) ? ($request->movesUp() ? $from - 1 : $from + 1) : -1;

        if (is_int($from) && $to >= 0 && $to < $ordered->count()) {
            $rows = $ordered->all();
            [$rows[$from], $rows[$to]] = [$rows[$to], $rows[$from]];

            $this->audit->log(
                action: 'final_project_field.moved',
                entity: $field,
                before: ['position' => $from + 1],
                after: ['position' => $to + 1],
                actor: $this->actor($request->user()),
            );

            DB::transaction(static function () use ($rows): void {
                foreach ($rows as $index => $row) {
                    if ((int) $row->getAttribute('position') !== $index + 1) {
                        $row->setAttribute('position', $index + 1);
                        $row->save();
                    }
                }
            });
        }

        return $this->backTo($project, (string) __('admin.final_project.submission_fields.moved'));
    }

    public function destroy(DeleteFinalProjectFieldRequest $request, FinalProject $project, FinalProjectField $field): RedirectResponse
    {
        $label = (string) $field->getAttribute('label');

        $this->audit->log(
            action: 'final_project_field.deleted',
            entity: $field,
            before: $this->audit->snapshot($field, self::AUDITED),
            after: null,
            actor: $this->actor($request->user()),
        );

        $field->delete();

        return $this->backTo($project, (string) __('admin.final_project.submission_fields.removed', ['label' => $label]));
    }

    private function lastPosition(FinalProject $project): int
    {
        return (int) FinalProjectField::query()
            ->where('final_project_id', $project->getKey())
            ->max('position');
    }

    private function actor(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }

    /**
     * Back to the same cohort's screen, on the fields card, with no editor
     * left open.
     */
    private function backTo(FinalProject $project, string $status): RedirectResponse
    {
        return redirect()
            ->to(route('admin.finalProject.index', ['cohort' => $project->getAttribute('cohort_id')]).'#submission-fields')
            ->with('status', $status);
    }
}
