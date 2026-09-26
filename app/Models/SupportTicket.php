<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketLevel;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Services\Permissions\RoleResolver;
use Carbon\CarbonImmutable;
use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A support ticket — the platform's "technical support" (D-124).
 *
 * Written by App\Services\Tickets\TicketWorkflow alone; every timestamp comes
 * from App\Services\Time\Clock (BR-07). Who reads which ticket is
 * scopeVisibleTo(), the same rule SupportTicketPolicy::view() applies to one:
 *
 *  · the participant who opened it;
 *  · every coordinator of its cohort;
 *  · the general supervisor, every ticket;
 *  · the system administrator, the tickets that reached them.
 *
 * A trainer reads none (D-124).
 *
 * @property string $id
 * @property string $number
 * @property string $opener_id
 * @property string|null $cohort_id
 * @property SupportTicketCategory $category
 * @property string $subject
 * @property SupportTicketStatus $status
 * @property SupportTicketLevel $level
 * @property string|null $assignee_id
 * @property CarbonImmutable|null $reached_system_admin_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by
 * @property CarbonImmutable $last_activity_at
 *
 * @see D-124 · BR-22, BR-23, BR-28 · CONSTITUTION art. 5, art. 22
 */
class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'support_tickets';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Nothing is filled from a request: TicketWorkflow sets each column by
     * name (art. 13, rule 8).
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @var array<string, string> */
    protected $attributes = [
        'status' => 'open',
        'level' => 'coordinator',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => SupportTicketCategory::class,
            'status' => SupportTicketStatus::class,
            'level' => SupportTicketLevel::class,
            'reached_system_admin_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    // --------------------------------------------------------- relationships

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opener_id');
    }

    /**
     * @return BelongsTo<Cohort, $this>
     */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return HasMany<SupportTicketEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(SupportTicketEntry::class)->orderBy('position');
    }

    // ---------------------------------------------------------------- scopes

    /**
     * The tickets this account may read — the list's rule, and the policy's.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $roles = app(RoleResolver::class);

        if (! $roles->isActive($user)) {
            return $query->whereIn('support_tickets.id', []);
        }

        if ($roles->isAdmin($user)) {
            return $query;
        }

        $coordinatorCohorts = $roles->coordinatorCohortIds($user);
        $isSystemAdmin = $roles->isSystemAdmin($user);

        return $query->where(static function (Builder $visible) use ($user, $coordinatorCohorts, $isSystemAdmin): void {
            $visible->where('opener_id', $user->getKey());

            if ($coordinatorCohorts !== []) {
                $visible->orWhereIn('cohort_id', $coordinatorCohorts);
            }

            if ($isSystemAdmin) {
                $visible->orWhereNotNull('reached_system_admin_at');
            }
        });
    }

    /**
     * The tickets waiting on this account now: still being handled, and held
     * at its level — the coordinator it sits with, any general supervisor at
     * that level, any system administrator at theirs. The rail's badge counts
     * these (D-124).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWaitingOn(Builder $query, User $user): Builder
    {
        $roles = app(RoleResolver::class);

        if (! $roles->isActive($user)) {
            return $query->whereIn('support_tickets.id', []);
        }

        $coordinatorCohorts = $roles->coordinatorCohortIds($user);
        $isAdmin = $roles->isAdmin($user);
        $isSystemAdmin = $roles->isSystemAdmin($user);

        return $query
            ->whereIn('status', [SupportTicketStatus::Open->value, SupportTicketStatus::InProgress->value])
            ->where(static function (Builder $held) use ($user, $coordinatorCohorts, $isAdmin, $isSystemAdmin): void {
                $held->whereIn('support_tickets.id', []);

                if ($coordinatorCohorts !== []) {
                    $held->orWhere(static fn (Builder $mine) => $mine
                        ->where('level', SupportTicketLevel::Coordinator->value)
                        ->where('assignee_id', $user->getKey())
                        ->whereIn('cohort_id', $coordinatorCohorts));
                }

                if ($isAdmin) {
                    $held->orWhere('level', SupportTicketLevel::Admin->value);
                }

                if ($isSystemAdmin) {
                    $held->orWhere('level', SupportTicketLevel::SystemAdmin->value);
                }
            });
    }

    /**
     * Tickets at the coordinator level, not closed, whose coordinator can no
     * longer act on them: none assigned, the person who opened it, or no
     * coordinator enrolment in the ticket's cohort (active or completed) held
     * by an active, undeleted account in a coordinating role. It is
     * TicketRouting::holderCanAct asked of the database, so the sweep picks
     * the orphans themselves — never a page of healthy tickets it then skips.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrphaned(Builder $query): Builder
    {
        return $query
            ->where('level', SupportTicketLevel::Coordinator->value)
            ->where('status', '!=', SupportTicketStatus::Closed->value)
            ->where(static function (Builder $orphan): void {
                $orphan->whereNull('assignee_id')
                    ->orWhereColumn('assignee_id', 'opener_id')
                    ->orWhereNotExists(static function (QueryBuilder $enrolled): void {
                        $enrolled->select('enrollments.id')
                            ->from('enrollments')
                            ->whereColumn('enrollments.cohort_id', 'support_tickets.cohort_id')
                            ->whereColumn('enrollments.user_id', 'support_tickets.assignee_id')
                            ->where('enrollments.role_in_cohort', EnrollmentRole::Coordinator->value)
                            ->whereIn('enrollments.status', [EnrollmentStatus::Active->value, EnrollmentStatus::Completed->value])
                            ->whereIn('enrollments.user_id', User::query()
                                ->where('status', UserStatus::Active->value)
                                ->whereIn('role', [UserRole::Coordinator->value, UserRole::Admin->value])
                                ->select('id'));
                    });
            });
    }

    /**
     * Resolved tickets whose day has run out: the participant neither replied
     * nor closed them, so the platform closes them now (D-124).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDueToClose(Builder $query, CarbonImmutable $now, int $hours): Builder
    {
        return $query
            ->where('status', SupportTicketStatus::Resolved->value)
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', $now->subHours($hours));
    }
}
