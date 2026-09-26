<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\SubmissionFieldType;
use App\Models\FinalProjectField;
use App\Presenters\Support\HandInRules;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * One field of a final project's hand-in form, as the general supervisor's
 * list shows it (D-121): its place, its title, its type, whether it is
 * required, and its rules in one line.
 *
 * `canMoveUp`/`canMoveDown` only decide which arrows are drawn; the move
 * endpoint leaves an end of the list where it is whatever it is asked, and its
 * policy is asked regardless (art. 5).
 *
 * @see BR-31 · PRD §9.14.2 · D-121 · PROJECT-CONTRACT §16
 */
final class SubmissionFieldRow extends ViewModel
{
    public static function from(FinalProjectField $field, int $number, int $total, ?string $selectedId = null): self
    {
        $type = $field->fieldType();
        $label = (string) $field->getAttribute('label');

        return new self([
            'id' => (string) $field->getKey(),
            'number' => $number,
            'label' => $label,
            'description' => Present::text($field->getAttribute('description')),
            'tips' => $field->tipLines(),
            'typeLabel' => $type->label(),
            'typeIcon' => self::iconFor($type),
            'isRequired' => $field->isRequired(),
            'requiredLabel' => (string) __($field->isRequired()
                ? 'admin.final_project.submission_fields.required'
                : 'admin.final_project.submission_fields.optional'),
            'requiredVariant' => $field->isRequired() ? 'brand' : 'neutral',
            'rulesLabel' => HandInRules::summary($field),
            'canMoveUp' => $number > 1,
            'canMoveDown' => $number < $total,
            'moveUpLabel' => (string) __('admin.final_project.submission_fields.move_up', ['label' => $label]),
            'moveDownLabel' => (string) __('admin.final_project.submission_fields.move_down', ['label' => $label]),
            'isSelected' => $selectedId === (string) $field->getKey(),
        ]);
    }

    private static function iconFor(SubmissionFieldType $type): string
    {
        return match ($type) {
            SubmissionFieldType::Url, SubmissionFieldType::Github => 'globe',
            SubmissionFieldType::File => 'file',
            SubmissionFieldType::Text, SubmissionFieldType::Textarea => 'chat',
        };
    }
}
