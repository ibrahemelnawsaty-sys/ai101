<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResourceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An item in the training resource pack: an uploaded file, an external link or a
 * video. `file_url` is a storage path outside the web root; a temporary signed URL
 * is minted per request after the permission check, never stored here.
 *
 * `download_count` must not move while an administrator is previewing an account
 * (BR-34); that guard lives in the download service, not in this model.
 *
 * @see BR-22, BR-23, BR-34 · PRD §7.6, §9.12 · PROJECT-CONTRACT §4, §11
 */
class Resource extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'resources';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'cohort_id',
        'week_id',
        'session_id',
        'title',
        'description',
        'type',
        'file_url',
        'external_url',
        'size',
        'uploaded_by',
        'download_count',
    ];

    /** @var list<string> */
    protected $hidden = [
        'file_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ResourceType::class,
            'size' => 'integer',
            'download_count' => 'integer',
        ];
    }

    /**
     * @var array<string, int>
     */
    protected $attributes = [
        'download_count' => 0,
    ];

    // --------------------------------------------------------- relationships

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ---------------------------------------------------------------- scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, ResourceType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $query->whereIn(
            'cohort_id',
            Enrollment::query()->where('user_id', $userId)->select('cohort_id')
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
     * BR-23: resources of the cohorts the account reaches, and nothing else.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn('cohort_id', $user->accessibleCohortIds());
    }
}
