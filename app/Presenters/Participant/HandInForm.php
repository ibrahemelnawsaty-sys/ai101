<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\FinalProjectField;
use App\Models\ProjectSubmission;
use App\Presenters\Support\HandInRules;
use App\Services\FinalProject\SubmissionFields;
use App\Support\ViewModel;
use Illuminate\Support\Collection;

/**
 * The final project's hand-in form, as the participant fills it in (D-121):
 * one entry per field the general supervisor defined, in their order, and the
 * server's upload limits said once above them.
 *
 * Built only after the controller has established that the project is
 * unlocked for this account — the fields are part of the brief (BR-16).
 *
 * A link or text answered in the previous version is offered again as the
 * field's value, so correcting one line does not mean retyping the others;
 * files are never pre-filled — every version is complete on its own (BR-19).
 *
 * @see BR-16, BR-19 · FR-PROJ-10 · PRD §9.14.2 · D-121 · PROJECT-CONTRACT §16
 */
final class HandInForm extends ViewModel
{
    /**
     * @param  Collection<int, FinalProjectField>  $fields  in order
     */
    public static function from(Collection $fields, ?ProjectSubmission $previous): self
    {
        $values = self::previousValues($previous);
        $rows = [];

        foreach ($fields as $field) {
            $rows[] = HandInFormField::from($field, $values[(string) $field->getKey()] ?? null);
        }

        $hasUploads = $fields->contains(static fn (FinalProjectField $field): bool => $field->isFile());
        $maxFiles = SubmissionFields::maxFilesPerHandIn();

        return new self([
            'fields' => $rows,
            'isEmpty' => $rows === [],
            'limitsNote' => $hasUploads
                ? (string) trans_choice('project.upload_limits', $maxFiles, [
                    'count' => $maxFiles,
                    'size' => HandInRules::size(SubmissionFields::maxRequestKilobytes()),
                ])
                : null,
        ]);
    }

    /**
     * What the previous version typed into each link and text field.
     *
     * @return array<string, string>
     */
    private static function previousValues(?ProjectSubmission $previous): array
    {
        if ($previous === null) {
            return [];
        }

        $values = [];

        foreach (SubmissionFields::read($previous->getAttribute('answers')) as $answer) {
            if ($answer['field_id'] !== null && $answer['value'] !== null) {
                $values[$answer['field_id']] = $answer['value'];
            }
        }

        return $values;
    }
}
