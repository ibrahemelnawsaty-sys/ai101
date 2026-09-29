<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One published override of an e-mail template's subject or body (BR-31).
 *
 * The default of every key lives in lang/{ar,en}/emails.php; a row here
 * replaces it for the language whose column is filled. A NULL column means
 * "follow the default", so a text reset to its original is a missing row, not a
 * copy of the original that would stop following later edits to the file.
 *
 * Rows are written only by Admin\EmailTemplateController and read only by
 * App\Services\Mail\EmailOverrides, which is the one place the letters learn
 * about them.
 *
 * @property string $key
 * @property string|null $ar
 * @property string|null $en
 * @property string|null $updated_by
 *
 * @see BR-31, BR-36 · PRD §9.16, §9.18 · D-114, D-136
 */
final class EmailTemplateOverride extends Model
{
    protected $table = 'email_template_overrides';

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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
