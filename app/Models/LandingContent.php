<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One published override of a landing-page text (BR-31).
 *
 * The default of every key lives in lang/{ar,en}/landing.php; a row here
 * replaces it for the language whose column is filled. A NULL column means
 * "follow the default", so a text reset to its original is a missing row, not
 * a copy of the original that would stop following later edits to the file.
 *
 * Rows are written only by Admin\LandingController and read only by
 * App\Services\Landing\LandingOverrides, which is the one place the public
 * pages learn about them.
 *
 * @property string $key
 * @property string|null $ar
 * @property string|null $en
 * @property string|null $updated_by
 *
 * @see BR-31, BR-36 · PRD §9.1, §9.18 · D-114, D-117
 */
final class LandingContent extends Model
{
    protected $table = 'landing_contents';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'key',
        'ar',
        'en',
        'updated_by',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Landing copy is managed by the system administrator only (D-117). Anyone
     * else — the general supervisor included — gets an empty set, failing
     * closed rather than open (Constitution, Article 7).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSystemAdmin()) {
            return $query;
        }

        return $query->whereIn('key', []);
    }
}
