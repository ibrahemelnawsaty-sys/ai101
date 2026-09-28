<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved version of a guide page (D-127). Written once, never updated:
 * a restore copies an older page into a NEW version and records which one it
 * came from in `restored_from`.
 *
 * `source` says how the page arrived — the editor, an uploaded file, a
 * restore, a copy from another cohort, or the seeder — so the history reads
 * without guessing.
 *
 * @see D-127 · BR-31
 */
class FinalProjectGuideVersion extends Model
{
    /** @use HasFactory<\Database\Factories\FinalProjectGuideVersionFactory> */
    use HasFactory;

    use HasUuids;

    public const SOURCE_EDITOR = 'editor';

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_RESTORE = 'restore';

    public const SOURCE_COPY = 'copy';

    public const SOURCE_SEED = 'seed';

    public const UPDATED_AT = null;

    protected $table = 'final_project_guide_versions';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'final_project_guide_id',
        'version',
        'html',
        'sha256',
        'bytes',
        'source',
        'restored_from',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'bytes' => 'integer',
            'restored_from' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FinalProjectGuide, $this>
     */
    public function guide(): BelongsTo
    {
        return $this->belongsTo(FinalProjectGuide::class, 'final_project_guide_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
