<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The final project's guide in one language (D-127).
 *
 * Two steps stand between a saved page and a trainee: the general supervisor
 * makes it AVAILABLE, then the cohort's primary coordinator PUBLISHES it.
 * Neither step is a view concern — FinalProjectGuidePolicy reads both on every
 * request, and withdrawing availability clears publication in the same write.
 *
 * The page itself lives in `versions()`: every save is a new row and the
 * highest number is what is shown, so an edit or a restore never overwrites
 * anything.
 *
 * @see D-127 · BR-15, BR-16, BR-31
 */
class FinalProjectGuide extends Model
{
    /** @use HasFactory<\Database\Factories\FinalProjectGuideFactory> */
    use HasFactory;

    use HasUuids;

    /** The languages a guide is kept in; Arabic is the one every rule leans on. */
    public const LOCALES = ['ar', 'en'];

    public const PRIMARY_LOCALE = 'ar';

    protected $table = 'final_project_guides';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'final_project_id',
        'locale',
        'is_available',
        'available_at',
        'available_by',
        'is_published',
        'published_at',
        'published_by',
        'announced_at',
        'staff_announced_at',
    ];

    /**
     * @var array<string, bool>
     */
    protected $attributes = [
        'is_available' => false,
        'is_published' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'available_at' => 'datetime',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'announced_at' => 'datetime',
            'staff_announced_at' => 'datetime',
        ];
    }

    public function isPrimaryLocale(): bool
    {
        return $this->getAttribute('locale') === self::PRIMARY_LOCALE;
    }

    // --------------------------------------------------------- relationships

    /**
     * @return BelongsTo<FinalProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(FinalProject::class, 'final_project_id');
    }

    /**
     * @return HasMany<FinalProjectGuideVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(FinalProjectGuideVersion::class)->orderByDesc('version');
    }

    /**
     * The version on show: the highest number.
     *
     * @return HasOne<FinalProjectGuideVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(FinalProjectGuideVersion::class)->ofMany('version', 'max');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function availabler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'available_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
