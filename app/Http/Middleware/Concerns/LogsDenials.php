<?php

declare(strict_types=1);

namespace App\Http\Middleware\Concerns;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * Every access refusal is written to the immutable trail with the caller's IP
 * before the 403 leaves the building (PRD §4.3, CONSTITUTION Art. 22).
 *
 * The refused path goes into the JSON payload, never into `entity_id`: that
 * column is a 36-character UUID and a path would silently truncate.
 *
 * @see BR-22, BR-23, BR-28, BR-33 · PRD §4.3, §12.2 · CONSTITUTION Art. 8, Art. 22
 */
trait LogsDenials
{
    /**
     * @param  array<string, mixed>  $context
     */
    protected function logDenial(
        AuditLogger $audit,
        Request $request,
        string $reason,
        ?string $entityType = null,
        ?string $entityId = null,
        array $context = [],
    ): void {
        $audit->record(
            action: AuditLogger::ACCESS_DENIED,
            entityType: $entityType,
            entityId: $entityId,
            before: null,
            after: array_merge([
                'reason' => $reason,
                'method' => $request->getMethod(),
                'path' => mb_substr($request->path(), 0, 200),
                'route' => $request->route()?->getName(),
            ], $context),
        );
    }
}
