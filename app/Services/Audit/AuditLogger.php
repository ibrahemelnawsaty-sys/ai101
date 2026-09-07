<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Exceptions\DomainException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Time\Clock;
use App\Support\ImpersonationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the immutable audit trail.
 *
 * Callers must invoke the logger *before* the operation they are recording
 * completes, from inside the same database transaction, so that the trail and
 * the change either both land or neither does. Rejections are logged outside
 * any transaction so that a rollback cannot erase them.
 *
 * audit_logs is append only: this service never updates or deletes a row.
 *
 * @see BR-10, BR-14, BR-27, BR-35 · CONSTITUTION art. 8, art. 22
 */
final class AuditLogger
{
    public const ATTENDANCE_CHECK_IN = 'attendance.check_in';

    public const ATTENDANCE_CHECK_OUT = 'attendance.check_out';

    public const ATTENDANCE_MANUAL_EDIT = 'attendance.manual_edit';

    public const ATTENDANCE_MARKED_ABSENT = 'attendance.marked_absent';

    public const ATTENDANCE_MARKED_INCOMPLETE = 'attendance.marked_incomplete';

    public const ATTENDANCE_RATE_RECOMPUTED = 'attendance.rate_recomputed';

    public const EVALUATION_RECORDED = 'evaluation.recorded';

    public const EVALUATION_REVISED = 'evaluation.revised';

    public const CERTIFICATE_ISSUED = 'certificate.issued';

    public const CERTIFICATE_OVERRIDDEN = 'certificate.overridden';

    public const CERTIFICATE_REVOKED = 'certificate.revoked';

    public const JOURNEY_STEP_CHANGED = 'journey.step_changed';

    public const FILE_STORED = 'file.stored';

    public const FILE_DELETED = 'file.deleted';

    public const ACCESS_DENIED = 'access.denied';

    /** Suffix appended to an action when the attempt was refused. */
    public const REJECTED_SUFFIX = '.rejected';

    private const USER_AGENT_MAX_LENGTH = 500;

    /** `audit_logs.entity_id` is char(36): a UUID fits, a route path does not. */
    private const ENTITY_ID_MAX_LENGTH = 36;

    /**
     * Record an operation against an Eloquent model.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        ?Model $entity = null,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
    ): AuditLog {
        return $this->record(
            action: $action,
            entityType: $entity?->getMorphClass(),
            entityId: $entity === null ? null : $this->stringKey($entity),
            before: $before,
            after: $after,
            actorId: $this->actorId($actor),
        );
    }

    /**
     * Record an operation by raw identifiers, for cases where no model
     * instance is at hand (scheduled jobs, deletions, external entities).
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $actorId = null,
    ): AuditLog {
        $log = new AuditLog();

        $log->setAttribute('actor_id', $actorId ?? $this->currentActorId());
        $log->setAttribute('action', $action);
        $log->setAttribute('entity_type', $entityType);
        $log->setAttribute('entity_id', $entityId);
        $log->setAttribute('before', $before);
        $log->setAttribute('after', $after);
        $log->setAttribute('ip_address', $this->currentIp());
        $log->setAttribute('user_agent', $this->currentUserAgent());
        // audit_logs carries created_at only: the table has no updated_at
        // column, because a row is written once and never touched again
        // (art. 8, AuditLog::UPDATED_AT is null for the same reason).
        $log->setAttribute('created_at', Clock::now());

        $log->save();

        return $log;
    }

    /**
     * Record a refused attempt. Every rejection is auditable with its IP.
     *
     * @param  array<string, mixed>  $context
     */
    public function reject(
        string $action,
        ?Model $entity,
        DomainException $reason,
        ?User $actor = null,
        array $context = [],
    ): AuditLog {
        return $this->record(
            action: $action.self::REJECTED_SUFFIX,
            entityType: $entity?->getMorphClass(),
            entityId: $entity === null ? null : $this->stringKey($entity),
            before: null,
            after: array_merge($context, [
                'reason_key' => $reason->langKey(),
                'status' => $reason->status(),
            ]),
            actorId: $this->actorId($actor),
        );
    }

    /**
     * Record a refusal that has no domain exception behind it: a middleware
     * turning a request away, a scope check that ended in 403, an attempt to
     * preview an account that may not be previewed.
     *
     * `entity_id` is a char(36) column holding an identifier, so a descriptor
     * that is not one - a route path, for instance - is carried in `after`
     * instead of being truncated into the key column and losing its meaning.
     *
     * @param  array<string, mixed>  $context
     */
    public function denied(
        string $action,
        ?string $entityType = null,
        ?string $target = null,
        array $context = [],
    ): AuditLog {
        $isIdentifier = is_string($target) && $target !== '' && mb_strlen($target) <= self::ENTITY_ID_MAX_LENGTH;

        return $this->record(
            action: $action,
            entityType: $entityType,
            entityId: $isIdentifier ? $target : null,
            before: null,
            after: array_merge($context, [
                'outcome' => 'denied',
                'target' => $target,
            ]),
            actorId: null,
        );
    }

    /**
     * A refusal raised by a Policy while serving a request.
     *
     * PRD Â§4.3 requires every unauthorised attempt to answer 403 AND reach the
     * trail with the caller's IP. The entity recorded is the most specific model
     * the route bound - the resource the caller actually named - so a tampered
     * identifier is visible in `entity_id` rather than buried in a path string.
     *
     * @see BR-22, BR-23, BR-28 Â· PRD Â§4.3 Â· CONSTITUTION Art. 22
     */
    public function deniedRequest(Request $request, string $reason): AuditLog
    {
        $entity = $this->routeEntity($request);

        return $this->record(
            action: self::ACCESS_DENIED,
            entityType: $entity?->getMorphClass(),
            entityId: $entity === null ? null : $this->stringKey($entity),
            before: null,
            after: [
                'reason' => $reason,
                'method' => $request->getMethod(),
                'path' => mb_substr($request->path(), 0, 200),
                'route' => $request->route()?->getName(),
            ],
        );
    }

    /**
     * The most specific model the route resolved, or null when it bound none.
     */
    private function routeEntity(Request $request): ?Model
    {
        $entity = null;

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                $entity = $parameter;
            }
        }

        return $entity;
    }

    /**
     * A serialisable snapshot of a model, limited to the listed columns so the
     * trail never captures password hashes or tokens.
     *
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    public function snapshot(Model $model, array $columns): array
    {
        $snapshot = [];

        foreach ($columns as $column) {
            $value = $model->getAttribute($column);

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $value = Clock::toUtc($value)->format('Y-m-d H:i:s');
            }

            $snapshot[$column] = $value;
        }

        return $snapshot;
    }

    private function actorId(?User $actor): ?string
    {
        if ($actor === null) {
            return $this->currentActorId();
        }

        $key = $actor->getKey();

        return $key === null ? $this->currentActorId() : (string) $key;
    }

    private function stringKey(Model $model): ?string
    {
        $key = $model->getKey();

        return $key === null ? null : (string) $key;
    }

    /**
     * The acting user. During impersonation the *real* administrator is the
     * actor, never the impersonated account (BR-35).
     */
    private function currentActorId(): ?string
    {
        $request = $this->currentRequest();

        if ($request !== null && $request->hasSession()) {
            // The preview payload is the single source of "who is really acting":
            // App\Support\ImpersonationContext owns that session key (BR-35).
            $impersonator = ImpersonationContext::adminId();

            if (is_string($impersonator) && $impersonator !== '') {
                return $impersonator;
            }
        }

        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }

    private function currentIp(): ?string
    {
        return $this->currentRequest()?->ip();
    }

    private function currentUserAgent(): ?string
    {
        $agent = $this->currentRequest()?->userAgent();

        if (! is_string($agent) || $agent === '') {
            return null;
        }

        return mb_substr($agent, 0, self::USER_AGENT_MAX_LENGTH);
    }

    /**
     * The HTTP request being served, or null when there is not one.
     *
     * `runningInConsole()` alone is too blunt: the whole test suite runs under
     * the CLI SAPI, so it also silenced the IP on every request the feature
     * tests dispatch through the HTTP kernel - and PRD §4.3 requires the IP on
     * every refusal, which is exactly what those tests assert. Excluding the
     * test runner keeps the guard where it was meant to be (an artisan command
     * has no caller and must not borrow a synthetic Request) while leaving a
     * simulated HTTP request visible.
     */
    private function currentRequest(): ?Request
    {
        if (App::runningInConsole() && ! App::runningUnitTests()) {
            return null;
        }

        if (! App::bound('request')) {
            return null;
        }

        $request = App::make('request');

        return $request instanceof Request ? $request : null;
    }
}
