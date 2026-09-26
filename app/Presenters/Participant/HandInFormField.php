<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\SubmissionFieldType;
use App\Models\FinalProjectField;
use App\Presenters\Support\HandInRules;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * One field of the hand-in form (D-121): its label, what it asks, its tips,
 * and for an upload field the formats and limits the server will hold it to.
 *
 * `name` is what the control posts (`answers[{id}]`, and `answers[{id}][]`
 * for files); `errorKey` is where the server files its refusal
 * (`answers.{id}`), so the message lands under the field it is about.
 * `accept` only steers the browser's file dialog — the server checks the
 * extension and the bytes regardless (art. 5).
 *
 * @see FR-PROJ-10 · PRD §9.14.2 · D-121 · PROJECT-CONTRACT §16
 */
final class HandInFormField extends ViewModel
{
    public static function from(FinalProjectField $field, ?string $previousValue = null): self
    {
        $type = $field->fieldType();
        $id = (string) $field->getKey();
        $isFile = $type->isFile();

        return new self([
            'id' => $id,
            'domId' => 'hand-in-'.$id,
            'name' => 'answers['.$id.']'.($isFile ? '[]' : ''),
            'errorKey' => 'answers.'.$id,
            'label' => (string) $field->getAttribute('label'),
            'description' => Present::text($field->getAttribute('description')),
            'tips' => $field->tipLines(),
            'isRequired' => $field->isRequired(),
            'optionalMark' => $field->isRequired() ? null : (string) __('project.optional_mark'),
            'isFile' => $isFile,
            'isTextarea' => $type === SubmissionFieldType::Textarea,
            'inputType' => $type->isLink() ? 'url' : 'text',
            'isLtr' => $type->isLink(),
            'placeholder' => match ($type) {
                SubmissionFieldType::Url => 'https://',
                SubmissionFieldType::Github => 'https://github.com/',
                default => null,
            },
            'maxLength' => $isFile ? null : $type->maxLength(),
            'accept' => $isFile ? HandInRules::accept($field) : null,
            'isMultiple' => $isFile && $field->maxFiles() > 1,
            'formatsLabel' => $isFile
                ? (string) __('project.field_formats', ['formats' => HandInRules::formats($field)])
                : null,
            'limitsLabel' => $isFile
                ? (string) __('project.field_limits', [
                    'size' => HandInRules::size($field->maxKilobytes()),
                    'count' => HandInRules::count($field->maxFiles()),
                ])
                : null,
            'previousValue' => $isFile ? null : $previousValue,
        ]);
    }
}
