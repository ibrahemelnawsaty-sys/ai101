<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ThreadType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An internal conversation: a direct thread with a trainer, a cohort group, an
 * announcement channel — or, since D-118, a conversation one person started
 * with another, which belongs to no cohort.
 *
 * Visibility is deliberately stricter than cohort scope: a thread is reachable only
 * by the accounts listed in `thread_participants`, so a trainer never reads a
 * conversation they are not part of (BR-22). The one exception is the system
 * administrators' shared inbox (`inbox` = 'system_admin'): every system
 * administrator reads it, by role, and so does the one supervisor it belongs
 * to. Nobody else — the general supervisor included — reads anyone's
 * conversation except through the audited preview (D-118).
 *
 * @see BR-22, BR-23 · PRD §7.6, §9.13 · PROJECT-CONTRACT §4 · D-118
 */
class Thread extends Model
{
    /** @use HasFactory<\Database\Factories\ThreadFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'threads';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'cohort_id',
        'type',
        'inbox',
        'pair_key',
        'title',
        'created_by',
        'is_locked',
    ];

    /** The one shared inbox there is: the system administrators' (D-118). */
    public const INBOX_SYSTEM_ADMIN = 'system_admin';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ThreadType::class,
            'is_locked' => 'boolean',
        ];
    }

    /**
     * @var array<string, bool>
     */
    protected $attributes = [
        'is_locked' => false,
    ];

    // --------------------------------------------------------- relationships

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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ThreadParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ThreadParticipant::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'thread_participants')
            ->withPivot(['last_read_at', 'is_muted'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasOne<Message, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('sent_at');
    }

    // ---------------------------------------------------------------- scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, ThreadType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * Threads the account is actually a participant of.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $query->whereIn(
            'id',
            ThreadParticipant::query()->where('user_id', $userId)->select('thread_id'),
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCohort(Builder $query, Cohort|string $cohort): Builder
    {
        return $query->where('cohort_id', $cohort instanceof Cohort ? $cohort->getKey() : $cohort);
    }

    /**
     * The key of the inbox conversation between this supervisor and the system
     * administrators — one per supervisor, whoever starts it (D-118).
     */
    public static function inboxKeyFor(User|string $supervisor): string
    {
        return self::INBOX_SYSTEM_ADMIN.':'.($supervisor instanceof User ? $supervisor->getKey() : $supervisor);
    }

    /** The key of the one direct conversation between two people (D-118). */
    public static function pairKeyFor(User $one, User $other): string
    {
        $ids = [(string) $one->getKey(), (string) $other->getKey()];
        sort($ids);

        return implode('|', $ids);
    }

    public function isInbox(): bool
    {
        return $this->getAttribute('inbox') === self::INBOX_SYSTEM_ADMIN;
    }

    /** The supervisor an inbox conversation belongs to, read from its key. */
    public function inboxOwnerId(): ?string
    {
        $key = (string) $this->getAttribute('pair_key');
        $prefix = self::INBOX_SYSTEM_ADMIN.':';

        return $this->isInbox() && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : null;
    }

    /** Is this the supervisor the inbox conversation belongs to? */
    public function isInboxOwner(User $user): bool
    {
        return $this->isInbox() && $this->getAttribute('pair_key') === self::inboxKeyFor($user);
    }

    /**
     * BR-22 · D-118: who reads which conversation — ONE statement, for the
     * thread list, the rail's badge and (as ThreadPolicy::view) every single
     * thread. Membership decides, never role: the general supervisor no longer
     * reaches every thread, and a trainer no longer reaches another trainer's
     * direct line. The two exceptions:
     *   · a system administrator reads the shared inbox — and nothing else,
     *     not even a thread a leftover row from an earlier role still lists;
     *   · a member of an inbox thread reads it only while it is theirs: an
     *     account that stopped being a system administrator keeps a row there
     *     and loses the thread.
     * Anybody else's conversation is read through the audited preview alone.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSystemAdmin()) {
            return $query->where('inbox', self::INBOX_SYSTEM_ADMIN);
        }

        return $query
            ->whereIn('id', ThreadParticipant::query()->where('user_id', $user->getKey())->select('thread_id'))
            ->where(static fn (Builder $mine) => $mine
                ->whereNull('inbox')
                ->orWhere('pair_key', self::inboxKeyFor($user)));
    }
}
