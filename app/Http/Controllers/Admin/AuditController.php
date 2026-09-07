<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Presenters\Admin\AuditEntry;
use App\Presenters\Support\Options;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The audit trail (PRD §9.18).
 *
 * Read only, and read only by an administrator. There is no endpoint on this
 * platform that edits or deletes an entry, and there is no model method for it
 * either: the trail is append-only by construction, not by convention
 * (CONSTITUTION Art. 8).
 *
 * @see BR-27 · PRD §4.2, §9.18 · CONSTITUTION Art. 8, Art. 22
 */
final class AuditController extends Controller
{
    use ExportsCsv;

    private const PER_PAGE = 100;

    /** How many distinct values a filter dropdown offers. */
    private const FILTER_LIMIT = 100;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = AuditLog::query()->with('actor.profile')->orderByDesc('created_at');

        $action = $request->query('action');

        if (is_string($action) && $action !== '') {
            $query->where('action', $action);
        }

        $actor = $request->query('actor');

        if (is_string($actor) && $actor !== '') {
            $query->where('actor_id', $actor);
        }

        $entity = $request->query('entity');

        if (is_string($entity) && $entity !== '') {
            // The filter dropdown offers entity TYPES, while "view this
            // account's trail" links carry an entity ID. One clause serves
            // both rather than two filters that look the same.
            $query->where(static function ($builder) use ($entity): void {
                $builder->where('entity_id', $entity)->orWhere('entity_type', $entity);
            });
        }

        $from = $request->query('from');

        if (is_string($from) && $from !== '') {
            $query->whereDate('created_at', '>=', $from);
        }

        $to = $request->query('to');

        if (is_string($to) && $to !== '') {
            $query->whereDate('created_at', '<=', $to);
        }

        return view('admin.audit', [
            'contextLabel' => null,
            'entries' => $query->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(static fn (AuditLog $entry): AuditEntry => AuditEntry::from($entry)),
            'opened' => $this->opened($request),
            'actorOptions' => $this->actorOptions(),
            'actionOptions' => $this->actionOptions(),
            'entityOptions' => $this->entityOptions(),
            'errorState' => null,
        ]);
    }

    /**
     * The one entry a `?entry={id}` opens, with its before/after payloads.
     *
     * Read only, like everything else on this screen: there is no endpoint that
     * edits or deletes an entry, and no model method for it either (art. 8).
     */
    private function opened(Request $request): ?AuditEntry
    {
        $id = $request->query('entry');

        if (! is_string($id) || $id === '') {
            return null;
        }

        /** @var AuditLog|null $entry */
        $entry = AuditLog::query()->with('actor.profile')->find($id);

        return $entry === null ? null : AuditEntry::from($entry);
    }

    /**
     * Who has acted at all. Built from the trail itself rather than from the
     * accounts table, so the filter only ever offers actors that exist in it.
     *
     * @return list<array{value: string, label: string}>
     */
    private function actorOptions(): array
    {
        $ids = AuditLog::query()
            ->whereNotNull('actor_id')
            ->distinct()
            ->limit(self::FILTER_LIMIT)
            ->pluck('actor_id')
            ->all();

        return Options::fromModels(
            User::query()->with('profile')->whereIn('id', $ids)->get(),
            static fn (User $user): string => (string) (
                $user->profile?->getAttribute('full_name_ar') ?? $user->getAttribute('email')
            ),
        );
    }

    /**
     * The action codes present in the trail. They are machine codes and stay
     * that way in the filter: inventing an Arabic phrase for an unknown code
     * would misdescribe what happened (art. 4).
     *
     * @return list<array{value: string, label: string}>
     */
    private function actionOptions(): array
    {
        $actions = AuditLog::query()
            ->distinct()
            ->orderBy('action')
            ->limit(self::FILTER_LIMIT)
            ->pluck('action')
            ->all();

        $options = [];

        foreach ($actions as $action) {
            $options[] = ['value' => (string) $action, 'label' => (string) $action];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function entityOptions(): array
    {
        $types = AuditLog::query()
            ->whereNotNull('entity_type')
            ->distinct()
            ->orderBy('entity_type')
            ->limit(self::FILTER_LIMIT)
            ->pluck('entity_type')
            ->all();

        $options = [];

        foreach ($types as $type) {
            $options[] = ['value' => (string) $type, 'label' => class_basename((string) $type)];
        }

        return $options;
    }

    /** The same rows, as a CSV, for an auditor who wants them offline. */
    public function export(Request $request): Response
    {
        $this->authorize('export', AuditLog::class);

        $rows = AuditLog::query()
            ->with('actor')
            ->orderByDesc('created_at')
            ->limit(5000)
            ->get();

        $lines = ["\u{FEFF}".$this->csvRow([
            __('admin.audit.export.at'),
            __('admin.audit.export.actor'),
            __('admin.audit.export.action'),
            __('admin.audit.export.entity'),
            __('admin.audit.export.ip'),
        ])];

        foreach ($rows as $row) {
            $lines[] = $this->csvRow([
                (string) ($row->getAttribute('created_at')?->toIso8601String() ?? ''),
                (string) ($row->actor?->getAttribute('email') ?? ''),
                (string) $row->getAttribute('action'),
                (string) ($row->getAttribute('entity_type') ?? ''),
                (string) ($row->getAttribute('ip_address') ?? ''),
            ]);
        }

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="athar-audit.csv"',
        ]);
    }
}
