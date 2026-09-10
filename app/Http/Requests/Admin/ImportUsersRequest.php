<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\UploadRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Uploading a filled sheet of trainees.
 *
 * The file itself is checked the way every upload on this platform is checked —
 * `UploadRules` reads the MIME type from the CONTENT, not the extension, and
 * clamps the size against `athar.uploads.max_kilobytes`. What this adds is the
 * cohort: an import with no cohort would produce two hundred accounts belonging
 * to nothing, which is precisely the failure the whole invitation flow exists
 * to end.
 *
 * The rows inside are NOT validated here. A FormRequest answers about the
 * request; the sheet's contents are answered about by
 * `ParticipantImportReader`, one row at a time, with a message per row — a
 * single "the file is invalid" for a sheet with one bad phone number in row 34
 * would be useless.
 *
 * @see PRD §4.2, §12.5 · CONSTITUTION Art. 5 · D-63
 */
final class ImportUsersRequest extends FormRequest
{
    use UploadRules;

    /** A sheet of a few hundred rows is measured in tens of kilobytes. */
    private const MAX_KILOBYTES = 2048;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cohort_id' => ['required', 'string', Rule::exists('cohorts', 'id')],
            // `fileRules()` carries `file`, the extension allow-list and the
            // size cap, and deliberately not `required` — most callers accept
            // an optional file. This one does not: an import with no sheet is
            // not an import.
            'sheet' => array_merge(
                ['required'],
                $this->fileRules(self::MAX_KILOBYTES),
                // The extension list from config is wide by design (D-17). A
                // sheet is a sheet: narrowed here by the file's own bytes.
                ['mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,text/plain'],
            ),
        ];
    }

    public function cohortId(): string
    {
        return (string) $this->validated('cohort_id');
    }
}
