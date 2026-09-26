<?php

declare(strict_types=1);

namespace App\Presenters\Support;

use App\Enums\SubmissionFileFormat;
use App\Models\FinalProjectField;

/**
 * How a hand-in field's rules read on a screen (D-121): its formats, its
 * per-file size, its file count, and the one-line summary the supervisor's
 * list shows. One place, so the supervisor's list and the participant's form
 * can never describe the same field two ways.
 *
 * Every figure is the one the server enforces — FinalProjectField's clamped
 * readers — never the raw stored number (art. 5).
 *
 * @see PRD §9.14.2 · D-121 · CONSTITUTION art. 5, art. 15
 */
final class HandInRules
{
    /** The field's formats as one inline list, e.g. "PDF, PowerPoint" in the reader's own separator. */
    public static function formats(FinalProjectField $field): string
    {
        return implode(
            (string) __('app.list_separator'),
            array_map(static fn (SubmissionFileFormat $format): string => $format->label(), $field->formats()),
        );
    }

    /** A size in kilobytes as the reader's unit, e.g. "25 MB". */
    public static function size(int $kilobytes): string
    {
        return Present::fileSize($kilobytes * 1024) ?? '';
    }

    /** A file count with its plural form, e.g. "one file at most" or "3 files at most". */
    public static function count(int $files): string
    {
        return (string) trans_choice('assignments.file_limit', $files, ['count' => $files]);
    }

    /** The supervisor's one-line summary of a field's rules. */
    public static function summary(FinalProjectField $field): string
    {
        if ($field->isFile()) {
            return (string) __('admin.final_project.submission_fields.file_rules', [
                'formats' => self::formats($field),
                'size' => self::size($field->maxKilobytes()),
                'count' => self::count($field->maxFiles()),
            ]);
        }

        return (string) __('admin.final_project.submission_fields.text_rules', [
            'max' => $field->fieldType()->maxLength(),
        ]);
    }

    /**
     * The file picker's `accept` hint, e.g. ".pdf,.ppt,.pptx". A convenience for
     * the browser's dialog only — the server checks the extension and the
     * bytes again whatever the picker allowed (art. 5).
     */
    public static function accept(FinalProjectField $field): string
    {
        return implode(',', array_map(
            static fn (string $extension): string => '.'.$extension,
            $field->acceptedExtensions(),
        ));
    }
}
