<?php

declare(strict_types=1);

namespace App\Presenters\Shared;

use App\Enums\SubmissionFieldType;
use App\Models\ProjectSubmission;
use App\Services\FinalProject\SubmissionFields;
use App\Support\SignedFiles;
use App\Support\ViewModel;

/**
 * One item of a final-project hand-in, as the participant's own screen and
 * the trainer's grading panel both show it (D-121).
 *
 * The label and the type are the ones COPIED into the hand-in when it was
 * made, not the field's current ones: a field renamed or removed since then
 * still shows here as it was asked. Every file carries a signed link valid
 * fifteen minutes, minted after the page's own permission check, and the
 * download route asks the policy again on arrival (D-80).
 *
 * `href` is set only for an address that is plainly http(s): the value was
 * validated as https when it was handed in, and a template never places an
 * unchecked string in an href (art. 24).
 *
 * @see BR-19, BR-22, BR-23 · FR-PROJ-09, FR-PROJ-10 · PRD §9.14.2, §12.5 · D-80, D-121
 */
final class HandInAnswer extends ViewModel
{
    /**
     * Every item of one hand-in, in the order it was handed in.
     *
     * @return list<self>
     */
    public static function collection(ProjectSubmission $submission): array
    {
        $rows = [];

        foreach (SubmissionFields::read($submission->getAttribute('answers')) as $position => $answer) {
            $type = $answer['type'];
            $files = $type->isFile()
                ? FileLink::collection(
                    $answer['files'],
                    SignedFiles::for('files.projectSubmissionAnswer', 'projectSubmission', $submission, ['answer' => $position]),
                )
                : [];

            $rows[] = new self([
                'label' => $answer['label'] !== '' ? $answer['label'] : '—',
                'typeLabel' => $type->label(),
                'isFile' => $type->isFile(),
                'isLink' => $type->isLink(),
                'isLongText' => $type === SubmissionFieldType::Textarea,
                'value' => $answer['value'],
                'href' => $type->isLink() ? self::href($answer['value']) : null,
                'files' => $files,
                'isEmpty' => $type->isFile() ? $files === [] : $answer['value'] === null,
            ]);
        }

        return $rows;
    }

    private static function href(?string $value): ?string
    {
        return $value !== null && preg_match('#^https?://#i', $value) === 1 ? $value : null;
    }
}
