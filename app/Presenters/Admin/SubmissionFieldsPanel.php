<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\SubmissionFieldType;
use App\Enums\SubmissionFileFormat;
use App\Models\FinalProject;
use App\Models\FinalProjectField;
use App\Presenters\Support\HandInRules;
use App\Services\FinalProject\SubmissionFields;
use App\Support\ViewModel;
use Illuminate\Support\Collection;

/**
 * The "hand-in fields" card on the general supervisor's final-project screen
 * (D-121): the list, the server's limits, the warning when the fields together
 * allow a hand-in heavier than one request, and the editor or the removal
 * prompt when the address asks for one.
 *
 * `?field=new` opens a blank editor, `?field={id}` the editor of that field,
 * `?remove={id}` the removal prompt — each id matched against THIS project's
 * fields only, so an id from another project opens nothing. The write
 * endpoints check their own policy regardless (art. 5).
 *
 * @see BR-31, BR-36 · PRD §9.14.2 · D-121 · CONSTITUTION art. 5, art. 10 · PROJECT-CONTRACT §16
 */
final class SubmissionFieldsPanel extends ViewModel
{
    /**
     * @param  Collection<int, FinalProjectField>  $fields  the project's fields, in order
     */
    public static function from(FinalProject $project, Collection $fields, ?string $editId, ?string $removeId): self
    {
        $fields = $fields->values();
        $total = $fields->count();

        $rows = [];

        foreach ($fields as $index => $field) {
            $rows[] = SubmissionFieldRow::from($field, $index + 1, $total, $editId);
        }

        $find = static fn (?string $id): ?FinalProjectField => $id === null
            ? null
            : $fields->first(static fn (FinalProjectField $field): bool => (string) $field->getKey() === $id);

        $editing = $find($editId);
        $removing = $find($removeId);

        $largest = SubmissionFields::largestHandInKilobytes($fields);
        $limit = SubmissionFields::maxRequestKilobytes();

        return new self([
            'projectId' => (string) $project->getKey(),
            'rows' => $rows,
            'isEmpty' => $rows === [],
            'limitsNote' => (string) __('admin.final_project.submission_fields.limits_note', [
                'files' => HandInRules::count(SubmissionFields::maxFilesPerHandIn()),
                'size' => HandInRules::size($limit),
                'file_size' => HandInRules::size(FinalProjectField::platformMaxKilobytes()),
            ]),
            'heavyWarning' => $largest > $limit
                ? (string) __('admin.final_project.submission_fields.heavy_warning', [
                    'size' => HandInRules::size($largest),
                    'limit' => HandInRules::size($limit),
                ])
                : null,
            'editor' => match (true) {
                $editId === 'new' => SubmissionFieldForm::blank(),
                $editing !== null => SubmissionFieldForm::from($editing),
                default => null,
            },
            'removal' => $removing === null ? null : [
                'id' => (string) $removing->getKey(),
                'title' => (string) __('admin.final_project.submission_fields.remove_title', [
                    'label' => (string) $removing->getAttribute('label'),
                ]),
            ],
            'typeOptions' => array_map(
                static fn (SubmissionFieldType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'description' => (string) __('admin.final_project.submission_fields.form.type_descriptions.'.$type->value),
                ],
                SubmissionFieldType::cases(),
            ),
            'fileType' => SubmissionFieldType::File->value,
            'formatOptions' => array_map(
                static fn (SubmissionFileFormat $format): array => [
                    'value' => $format->value,
                    'label' => $format->label(),
                    'description' => implode(' ', array_map(
                        static fn (string $extension): string => '.'.$extension,
                        $format->extensions(),
                    )),
                ],
                SubmissionFileFormat::cases(),
            ),
            'maxMegabytesHint' => (string) __('admin.final_project.submission_fields.form.max_megabytes_hint', [
                'max' => max(1, intdiv(FinalProjectField::platformMaxKilobytes(), 1024)),
            ]),
            'maxFilesHint' => (string) __('admin.final_project.submission_fields.form.max_files_hint', [
                'max' => SubmissionFields::maxFilesPerHandIn(),
                'total' => HandInRules::count(SubmissionFields::maxFilesPerHandIn()),
            ]),
            'maxMegabytes' => max(1, intdiv(FinalProjectField::platformMaxKilobytes(), 1024)),
            'maxFiles' => SubmissionFields::maxFilesPerHandIn(),
        ]);
    }
}
