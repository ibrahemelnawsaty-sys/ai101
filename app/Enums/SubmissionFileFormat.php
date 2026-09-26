<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * A file format an administrator may accept in one upload field of the
 * final-project hand-in (D-121).
 *
 * Every case is drawn from what the platform ALREADY accepts: its extensions
 * are in `athar.uploads.allowed_extensions` and its sniffed types are in
 * PrivateFileService's allow-list. This enum narrows that list per field; it
 * never widens it — the platform-wide list is still D-17's to settle, and a
 * test holds the two in step.
 *
 * `mimeTypes()` is what content sniffing reports for a genuine file of the
 * format, so the check runs on the bytes and not on the name (art. 24). The
 * OOXML formats (pptx, docx, xlsx) are ZIP containers: the platform names one
 * by its package structure, not by libmagic's first look (OfficeOpenXml), so
 * `application/zip` is NOT one of their types — an archive renamed `.pptx` is
 * refused by a deck field (D-121, security review).
 *
 * @see PROJECT-CONTRACT.md §3 · PRD §12.5 · D-17, D-121 · CONSTITUTION art. 22, art. 24
 */
enum SubmissionFileFormat: string
{
    use HasEnumValues;

    case Pdf = 'pdf';
    case Powerpoint = 'powerpoint';
    case Word = 'word';
    case Excel = 'excel';
    case Csv = 'csv';
    case Text = 'text';
    case Markdown = 'markdown';
    case Zip = 'zip';
    case Png = 'png';
    case Jpeg = 'jpeg';
    case Webp = 'webp';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.submission_file_format.'.$this->value);
    }

    /**
     * The file-name extensions of this format, lower case, without the dot.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Pdf => ['pdf'],
            self::Powerpoint => ['ppt', 'pptx'],
            self::Word => ['doc', 'docx'],
            self::Excel => ['xls', 'xlsx'],
            self::Csv => ['csv'],
            self::Text => ['txt'],
            self::Markdown => ['md'],
            self::Zip => ['zip'],
            self::Png => ['png'],
            self::Jpeg => ['jpg', 'jpeg'],
            self::Webp => ['webp'],
        };
    }

    /**
     * The types content sniffing may report for a genuine file of this format.
     *
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return match ($this) {
            self::Pdf => ['application/pdf'],
            self::Powerpoint => [
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ],
            self::Word => [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            self::Excel => [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            self::Csv => ['text/csv', 'text/plain'],
            self::Text => ['text/plain'],
            self::Markdown => ['text/markdown', 'text/plain'],
            self::Zip => ['application/zip'],
            self::Png => ['image/png'],
            self::Jpeg => ['image/jpeg'],
            self::Webp => ['image/webp'],
        };
    }

    /**
     * Every extension of the given formats, once each.
     *
     * @param  iterable<self>  $formats
     * @return list<string>
     */
    public static function extensionsOf(iterable $formats): array
    {
        $extensions = [];

        foreach ($formats as $format) {
            array_push($extensions, ...$format->extensions());
        }

        return array_values(array_unique($extensions));
    }

    /**
     * Every sniffed type the given formats accept, once each.
     *
     * @param  iterable<self>  $formats
     * @return list<string>
     */
    public static function mimeTypesOf(iterable $formats): array
    {
        $types = [];

        foreach ($formats as $format) {
            array_push($types, ...$format->mimeTypes());
        }

        return array_values(array_unique($types));
    }

    /**
     * Stored values read back as formats. An unknown value is dropped, never
     * guessed at: a format nobody can name accepts nothing (art. 7).
     *
     * @return list<self>
     */
    public static function fromValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $formats = [];

        foreach ($values as $value) {
            $format = is_string($value) ? self::tryFrom($value) : null;

            if ($format !== null && ! in_array($format, $formats, true)) {
                $formats[] = $format;
            }
        }

        return $formats;
    }
}
