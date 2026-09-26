<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubmissionFieldType;
use App\Enums\SubmissionFileFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field of a final project's hand-in form, as the administrator defined it
 * (D-121): what it is called, what it asks for, whether it may be left empty,
 * and — for an upload — which formats, how large and how many files.
 *
 * A field is configuration, not a record of anybody's work: every hand-in
 * copies the field's label and type next to the value it received
 * (`project_submissions.answers`), so editing or removing a field later never
 * rewrites what a participant already handed in (BR-19's spirit).
 *
 * The limits read back through maxKilobytes() and maxFiles() are clamped to the
 * platform ceilings in config/athar.php on every read, so a row written under a
 * looser ceiling can never outgrow the current one (BR-36, art. 7).
 *
 * @see BR-19, BR-31, BR-36 · PRD §9.14.2 · D-110, D-121
 */
class FinalProjectField extends Model
{
    /** @use HasFactory<\Database\Factories\FinalProjectFieldFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * At most this many fields per project: the hand-in stays one readable
     * form and one bounded request on shared hosting (art. 10).
     */
    public const MAX_PER_PROJECT = 20;

    protected $table = 'final_project_fields';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'final_project_id',
        'type',
        'label',
        'description',
        'tips',
        'is_required',
        'accepted_formats',
        'max_kilobytes',
        'max_files',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SubmissionFieldType::class,
            'tips' => 'array',
            'is_required' => 'boolean',
            'accepted_formats' => 'array',
            'max_kilobytes' => 'integer',
            'max_files' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @var array<string, bool|int>
     */
    protected $attributes = [
        'is_required' => true,
        'position' => 0,
    ];

    // --------------------------------------------------------- relationships

    /**
     * @return BelongsTo<FinalProject, $this>
     */
    public function finalProject(): BelongsTo
    {
        return $this->belongsTo(FinalProject::class);
    }

    // ------------------------------------------------------------- reading

    public function fieldType(): SubmissionFieldType
    {
        $type = $this->getAttribute('type');

        return $type instanceof SubmissionFieldType ? $type : SubmissionFieldType::Text;
    }

    public function isFile(): bool
    {
        return $this->fieldType()->isFile();
    }

    public function isRequired(): bool
    {
        return (bool) $this->getAttribute('is_required');
    }

    /**
     * @return list<SubmissionFileFormat>
     */
    public function formats(): array
    {
        return SubmissionFileFormat::fromValues($this->getAttribute('accepted_formats'));
    }

    /** @return list<string> */
    public function acceptedExtensions(): array
    {
        return SubmissionFileFormat::extensionsOf($this->formats());
    }

    /** @return list<string> */
    public function acceptedMimeTypes(): array
    {
        return SubmissionFileFormat::mimeTypesOf($this->formats());
    }

    /** The largest file this field accepts, never above the platform's own. */
    public function maxKilobytes(): int
    {
        $platform = self::platformMaxKilobytes();
        $stored = (int) $this->getAttribute('max_kilobytes');

        return $stored > 0 ? min($stored, $platform) : $platform;
    }

    /** How many files this field accepts, never above the platform's own. */
    public function maxFiles(): int
    {
        $stored = (int) $this->getAttribute('max_files');

        return max(1, min($stored > 0 ? $stored : 1, self::platformMaxFiles()));
    }

    /**
     * The tips, one per line, blank lines dropped.
     *
     * @return list<string>
     */
    public function tipLines(): array
    {
        $tips = $this->getAttribute('tips');

        if (! is_array($tips)) {
            return [];
        }

        $lines = [];

        foreach ($tips as $tip) {
            if (is_string($tip) && trim($tip) !== '') {
                $lines[] = trim($tip);
            }
        }

        return $lines;
    }

    // ---------------------------------------------------- platform ceilings

    /** The per-file ceiling of the whole platform (PRD §9.11.2, BR-36). */
    public static function platformMaxKilobytes(): int
    {
        $configured = config('athar.uploads.max_kilobytes');

        return is_int($configured) && $configured > 0 ? $configured : 25600;
    }

    /**
     * How many files one request may carry. PHP drops the rest SILENTLY
     * (`max_file_uploads`), so the hand-in's upload fields share this number
     * between them rather than each having it (D-121).
     */
    public static function platformMaxFiles(): int
    {
        $configured = config('athar.uploads.max_files');

        return is_int($configured) && $configured > 0 ? $configured : 5;
    }

    // ---------------------------------------------------------------- scopes

    /**
     * The order the hand-in form shows them in.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('created_at')->orderBy('id');
    }
}
