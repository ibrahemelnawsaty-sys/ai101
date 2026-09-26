<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Enums\SupportTicketLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Cohort;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Cohorts\PrimaryCoordinator;
use App\Services\Permissions\RoleResolver;

/**
 * Where a support ticket goes, and who holds it (D-124) — the one statement of
 * the route, read by the workflow that moves tickets, the policy that lets
 * people act on them and the notices that tell them.
 *
 *  · A new ticket reaches its cohort's PRIMARY coordinator. A cohort without
 *    one sends it to the general supervisor, so none is lost (the owner's
 *    safety net).
 *  · At the coordinator level the ticket is held by one coordinator — the
 *    primary, or the one it was handed to. At the level above, by every
 *    general supervisor; at the top, by every system administrator.
 *  · Staff read a ticket's internal lines; the participant who opened it
 *    never does, whatever else their account is.
 *
 * @see D-124 · BR-22, BR-23, BR-28 · CONSTITUTION art. 5, art. 7, art. 22
 */
final class TicketRouting
{
    public function __construct(
        private readonly RoleResolver $roles,
        private readonly PrimaryCoordinator $primary,
    ) {}

    /**
     * The level and the coordinator a ticket from this cohort reaches first.
     * Never the person who opened it: a coordinator who is also a trainee
     * somewhere does not receive their own ticket — it goes to the general
     * supervisor instead, like any ticket that has no primary coordinator.
     *
     * @return array{0: SupportTicketLevel, 1: string|null}
     */
    public function entryFor(?Cohort $cohort, User $opener): array
    {
        $primaryId = $cohort === null ? null : $this->primary->idOf($cohort);

        return $primaryId === null || $primaryId === (string) $opener->getKey()
            ? [SupportTicketLevel::Admin, null]
            : [SupportTicketLevel::Coordinator, $primaryId];
    }

    /** Does this account hold the ticket — is it theirs to act on now? */
    public function holds(User $user, SupportTicket $ticket): bool
    {
        if (! $this->roles->isActive($user) || $this->opened($user, $ticket)) {
            return false;
        }

        return match ($ticket->level) {
            SupportTicketLevel::Coordinator => $ticket->assignee_id !== null
                && (string) $ticket->assignee_id === (string) $user->getKey()
                && $this->roles->isCoordinatorOf($user, $ticket->cohort_id),
            SupportTicketLevel::Admin => $this->roles->isAdmin($user),
            SupportTicketLevel::SystemAdmin => $this->roles->isSystemAdmin($user),
        };
    }

    /**
     * Does this account read the ticket as support staff — internal lines and
     * all? Every coordinator of its cohort, every general supervisor, and a
     * system administrator once it reached them. Never the person who opened
     * it (D-124).
     */
    public function readsAsStaff(User $user, SupportTicket $ticket): bool
    {
        if (! $this->roles->isActive($user) || $this->opened($user, $ticket)) {
            return false;
        }

        return $this->roles->isAdmin($user)
            || $this->roles->isCoordinatorOf($user, $ticket->cohort_id)
            || ($this->roles->isSystemAdmin($user) && $ticket->reached_system_admin_at !== null);
    }

    public function opened(User $user, SupportTicket $ticket): bool
    {
        return (string) $user->getKey() === (string) $ticket->opener_id;
    }

    /** Is this account the primary coordinator of the ticket's cohort now? */
    public function isPrimaryCoordinator(User $user, SupportTicket $ticket): bool
    {
        $primaryId = $this->primaryIdOf($ticket);

        return $primaryId !== null && $primaryId === (string) $user->getKey();
    }

    /**
     * The coordinators a ticket can be handed or returned to: the cohort's
     * coordinators who can act (PrimaryCoordinator::coordinatorIds) — never
     * the person who opened it.
     *
     * @return list<string>
     */
    public function coordinatorIds(SupportTicket $ticket): array
    {
        $cohort = $this->cohortOf($ticket);

        if ($cohort === null) {
            return [];
        }

        return array_values(array_filter(
            $this->primary->coordinatorIds($cohort),
            static fn (string $id): bool => $id !== (string) $ticket->opener_id,
        ));
    }

    /**
     * The cohort's primary coordinator now — or null when it has none, or
     * when the primary coordinator is the person who opened this ticket.
     */
    public function primaryIdOf(SupportTicket $ticket): ?string
    {
        $cohort = $this->cohortOf($ticket);
        $primaryId = $cohort === null ? null : $this->primary->idOf($cohort);

        return $primaryId === (string) $ticket->opener_id ? null : $primaryId;
    }

    /**
     * Can the coordinator the ticket sits with still act on it? The policy's
     * own question (holds), asked of the assignee: an active account, a
     * coordinator of the ticket's cohort by RoleResolver's rule, and not the
     * person who opened it. False once they left the cohort, were suspended,
     * deleted or moved to another role — the ticket then goes back to the
     * primary coordinator, or up to the general supervisor
     * (TicketWorkflow::rehome). SupportTicket::scopeOrphaned asks the same of
     * the database.
     */
    public function holderCanAct(SupportTicket $ticket): bool
    {
        if ($ticket->level !== SupportTicketLevel::Coordinator) {
            return true;
        }

        if ($ticket->assignee_id === null || (string) $ticket->assignee_id === (string) $ticket->opener_id) {
            return false;
        }

        $assignee = User::query()->find($ticket->assignee_id);

        return $assignee instanceof User && $this->roles->isCoordinatorOf($assignee, $ticket->cohort_id);
    }

    /**
     * The people told that a ticket reached a level: the coordinator it sits
     * with, or every active general supervisor, or every active system
     * administrator.
     *
     * @return list<string>
     */
    public function peopleAt(SupportTicket $ticket): array
    {
        $role = match ($ticket->level) {
            SupportTicketLevel::Coordinator => null,
            SupportTicketLevel::Admin => UserRole::Admin,
            SupportTicketLevel::SystemAdmin => UserRole::SystemAdmin,
        };

        // A holder who can no longer act hears nothing: the ticket is on its
        // way to someone who can (TicketWorkflow::rehome), who hears then.
        if ($role === null) {
            return $this->holderCanAct($ticket) ? [(string) $ticket->assignee_id] : [];
        }

        return array_values(User::query()
            ->where('role', $role->value)
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($ticket->opener_id)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
    }

    private function cohortOf(SupportTicket $ticket): ?Cohort
    {
        if ($ticket->cohort_id === null) {
            return null;
        }

        if ($ticket->relationLoaded('cohort')) {
            $cohort = $ticket->getRelation('cohort');

            return $cohort instanceof Cohort ? $cohort : null;
        }

        return Cohort::query()->find($ticket->cohort_id);
    }
}
