<?php

declare(strict_types=1);

namespace App\Services\Permissions;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use App\Support\ImpersonationContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Starting and ending an account preview.
 *
 * The preview is the strongest permission on the platform, so every rule is
 * re-checked here even though the Policy already checked it: an admin may not
 * preview another admin (BR-35), a preview lasts at most 30 minutes, and both
 * its start and its end are written to the audit log before they take effect.
 *
 * Nothing here touches the previewed user's own traces — no last-login stamp,
 * no read markers, no counters (BR-34). `ImpersonationContext::begin()` runs
 * *before* the auth swap precisely so that any login listener can see that a
 * preview is in progress and stand down.
 *
 * @see BR-27, BR-33, BR-34, BR-35 · PRD §4.5 · CONSTITUTION Art. 23
 */
final class ImpersonationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RoleResolver $roles,
    ) {}

    /**
     * @throws AuthorizationException when the target may not be previewed
     */
    public function start(User $admin, User $target): ImpersonationSession
    {
        $this->assertCanPreview($admin, $target);

        /** @var ImpersonationSession $record */
        $record = DB::transaction(function () use ($admin, $target): ImpersonationSession {
            // BR-35 records the preview SESSION, not the account looked at: the
            // trail's entity is the `impersonation_sessions` row (CONTRACT §4),
            // so start and stop point at the same identifier and the audit
            // screen can put the pair back together. The key is minted before
            // the insert so the log can name it and still be written inside the
            // same transaction, before the row lands (art. 8).
            $record = new ImpersonationSession([
                'admin_id' => $admin->getKey(),
                'target_id' => $target->getKey(),
                'started_at' => Clock::now(),
                'ended_at' => null,
            ]);

            $record->setAttribute($record->getKeyName(), $record->newUniqueId());

            $this->audit->log('impersonation.start', $record, null, [
                'admin_id' => (string) $admin->getKey(),
                'target_id' => (string) $target->getKey(),
            ], $admin);

            $record->save();

            return $record;
        });

        // Order matters: the context is armed first so that listeners on the
        // login event can detect the preview and skip every write (BR-34).
        ImpersonationContext::begin(
            (string) $admin->getKey(),
            (string) $target->getKey(),
            (string) $record->getKey(),
        );

        Auth::guard('web')->login($target, false);

        return $record;
    }

    /**
     * End the preview and return the admin to their own session.
     * Returns the restored admin, or null when no preview was running.
     */
    public function stop(): ?User
    {
        $payload = ImpersonationContext::clear();

        if ($payload === null) {
            return null;
        }

        /** @var User|null $admin */
        $admin = User::query()->find($payload['admin_id']);

        if ($admin === null || $admin->status !== UserStatus::Active) {
            // The admin account vanished or was suspended mid-preview: refuse to
            // restore anything and drop the session entirely (fail closed).
            Auth::guard('web')->logout();

            return null;
        }

        // A preview never lasts longer than its ceiling, whatever the clock says
        // when the expiry is finally noticed: the request that discovers a stale
        // preview may arrive an hour later, but the session ENDED at
        // started_at + 30 minutes (PRD §4.5.2, CONTRACT §4). Recording "now"
        // wrote a 90-minute preview into a table whose maximum is 30.
        $endedAt = $this->endInstantFor($payload['started_at']);

        DB::transaction(function () use ($payload, $admin, $endedAt): void {
            $this->audit->record(
                action: 'impersonation.stop',
                entityType: (new ImpersonationSession)->getMorphClass(),
                entityId: $payload['record_id'],
                before: null,
                after: [
                    'admin_id' => $payload['admin_id'],
                    'target_id' => $payload['target_id'],
                    'record_id' => $payload['record_id'],
                    'ended_at' => $endedAt->format('Y-m-d H:i:s'),
                ],
                actorId: (string) $admin->getKey(),
            );

            ImpersonationSession::query()
                ->whereKey($payload['record_id'])
                ->whereNull('ended_at')
                ->update(['ended_at' => $endedAt]);
        });

        Auth::guard('web')->login($admin, false);

        return $admin;
    }

    /**
     * When a preview that started at `$startedAt` actually ended: now, or the
     * 30-minute ceiling if that already passed — never later than the ceiling.
     */
    private function endInstantFor(string $startedAt): CarbonImmutable
    {
        $ceiling = CarbonImmutable::parse($startedAt, 'UTC')
            ->addMinutes(ImpersonationSession::MAX_DURATION_MINUTES);

        $now = Clock::now();

        return $now->greaterThan($ceiling) ? $ceiling : $now;
    }

    /** End the preview only when its 30-minute ceiling has been reached. */
    public function stopIfExpired(): ?User
    {
        if (! ImpersonationContext::isActive() || ! ImpersonationContext::hasExpired()) {
            return null;
        }

        return $this->stop();
    }

    public function canPreview(User $admin, User $target): bool
    {
        if (! $this->roles->isAdmin($admin) || ! $this->roles->isActive($admin)) {
            return false;
        }

        if ($admin->is($target)) {
            return false;
        }

        // BR-35: an admin account is never previewable.
        if ($target->role === UserRole::Admin) {
            return false;
        }

        return $target->status !== UserStatus::Deleted;
    }

    /**
     * @throws AuthorizationException
     */
    private function assertCanPreview(User $admin, User $target): void
    {
        if (! $this->canPreview($admin, $target)) {
            $this->audit->record(
                action: AuditLogger::ACCESS_DENIED,
                entityType: 'user',
                entityId: (string) $target->getKey(),
                before: null,
                after: ['reason' => 'impersonation.refused'],
                actorId: (string) $admin->getKey(),
            );

            throw new AuthorizationException;
        }
    }
}
