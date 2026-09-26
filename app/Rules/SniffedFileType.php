<?php

declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * An upload whose BYTES say it is one of the given types (D-124).
 *
 * Laravel's `mimetypes` rule asks the file object for its type, and a test's
 * fake upload answers from its NAME — so a PDF called `clip.mp4` passed the
 * form in every test while the storage service refused it with the platform's
 * generic wording. This rule reads the bytes with finfo, as
 * PrivateFileService does, so the form refuses what the service would, in
 * words written for the form. It is the first check, not the last: the
 * service sniffs again before anything is written (art. 24).
 *
 * @see D-124 · PRD §12.5 · CONSTITUTION art. 7, art. 24
 */
final class SniffedFileType implements ValidationRule
{
    /**
     * @param  list<string>  $allowed
     */
    public function __construct(
        private readonly array $allowed,
        private readonly string $message,
    ) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! in_array($this->sniff($value), $this->allowed, true)) {
            $fail($this->message);
        }
    }

    /** The type the bytes declare, or null when nothing can be read (refused). */
    private function sniff(mixed $value): ?string
    {
        if (! $value instanceof UploadedFile || ! $value->isValid() || ! function_exists('finfo_open')) {
            return null;
        }

        $path = $value->getRealPath();

        if ($path === false || $path === '') {
            return null;
        }

        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = @finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) && $mime !== '' ? strtolower(explode(';', $mime)[0]) : null;
    }
}
