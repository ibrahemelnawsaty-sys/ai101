<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\SubmissionFieldType;
use App\Enums\SubmissionFileFormat;
use App\Models\FinalProjectField;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * The editor for one hand-in field, blank or filled (D-121).
 *
 * The upload settings are always published, whatever the type, so switching
 * the type to "file" in the editor shows sensible starting values; the
 * request clears them for every other type when it saves.
 *
 * @see BR-31 · PRD §9.14.2 · D-121 · PROJECT-CONTRACT §16
 */
final class SubmissionFieldForm extends ViewModel
{
    public static function blank(): self
    {
        return new self([
            'exists' => false,
            'id' => null,
            'title' => (string) __('admin.final_project.submission_fields.add_title'),
            'type' => SubmissionFieldType::Url->value,
            'label' => '',
            'description' => '',
            'tipsLines' => '',
            'isRequired' => true,
            'formats' => [SubmissionFileFormat::Pdf->value],
            'maxMegabytes' => (string) self::megabytes(FinalProjectField::platformMaxKilobytes()),
            'maxFiles' => '1',
        ]);
    }

    public static function from(FinalProjectField $field): self
    {
        $label = (string) $field->getAttribute('label');
        $formats = array_map(static fn (SubmissionFileFormat $format): string => $format->value, $field->formats());

        return new self([
            'exists' => true,
            'id' => (string) $field->getKey(),
            'title' => (string) __('admin.final_project.submission_fields.edit_title', ['label' => $label]),
            'type' => $field->fieldType()->value,
            'label' => $label,
            'description' => Present::text($field->getAttribute('description')) ?? '',
            'tipsLines' => implode("\n", $field->tipLines()),
            'isRequired' => $field->isRequired(),
            'formats' => $formats === [] ? [SubmissionFileFormat::Pdf->value] : $formats,
            'maxMegabytes' => (string) self::megabytes($field->maxKilobytes()),
            'maxFiles' => (string) $field->maxFiles(),
        ]);
    }

    private static function megabytes(int $kilobytes): int
    {
        return max(1, intdiv($kilobytes, 1024));
    }
}
