<?php

declare(strict_types=1);

namespace App\Services\FinalProject;

use App\Enums\SubmissionFieldType;
use App\Enums\SubmissionFileFormat;
use App\Models\FinalProject;
use App\Models\FinalProjectField;
use App\Services\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The final project's hand-in fields: the default set, the one shape a
 * hand-in is stored in, and the upload ceilings the fields share (D-121).
 *
 * WHY ONE CLASS
 * Three writers produce hand-in entries — the participant's submit, the D-121
 * migration that carried the earlier fixed hand-in across, and the demo
 * seeders — and two readers consume them: the screens and the download route.
 * If each spelt the entry out for itself, a renamed key would leave a trainer
 * looking at a hand-in with no files in it and nothing failing. answer() is the
 * only writer of the shape and read() the only reader.
 *
 * THE SHARED CEILINGS
 * PHP drops every file past `max_file_uploads` in one request WITHOUT an
 * error, and refuses the whole body past `post_max_size` (art. 10). A hand-in
 * is one request, so its upload fields share those two numbers between them:
 * filesAskedFor() is how many files the fields ask for together — refused
 * above the platform's count when an administrator saves a field — and
 * largestHandInKilobytes() is the heaviest hand-in they allow, only warned
 * about, because a hand-in filled to every limit at once is rare and forbidding
 * it would shrink every field for everyone.
 *
 * @see BR-19, BR-31, BR-36 · FR-PROJ-10 · PRD §9.14.2, §12.5 · D-110, D-121 · CONSTITUTION art. 7, art. 10
 */
final class SubmissionFields
{
    /**
     * The default hand-in: the three deliverables of the owner's picture, then
     * D-110's optional idea logo and the optional description PRD §9.14.2
     * names (D-121).
     *
     * `key` is the column of the earlier fixed hand-in each one replaces — how
     * the D-121 migration carries an existing row across without loss — and
     * the lang key its texts come from (`project.default_fields.<key>`).
     * `max_kilobytes` null means the platform's own per-file ceiling.
     *
     * @var list<array{key: string, type: string, required: bool, formats?: list<string>, max_kilobytes?: int|null, max_files?: int}>
     */
    public const DEFAULTS = [
        ['key' => 'live_url', 'type' => SubmissionFieldType::Url->value, 'required' => true],
        ['key' => 'github_url', 'type' => SubmissionFieldType::Github->value, 'required' => true],
        [
            'key' => 'presentation_file',
            'type' => SubmissionFieldType::File->value,
            'required' => true,
            'formats' => [SubmissionFileFormat::Pdf->value, SubmissionFileFormat::Powerpoint->value],
            'max_kilobytes' => null,
            'max_files' => 1,
        ],
        [
            'key' => 'logo_file',
            'type' => SubmissionFieldType::File->value,
            'required' => false,
            'formats' => [SubmissionFileFormat::Png->value, SubmissionFileFormat::Jpeg->value, SubmissionFileFormat::Webp->value],
            // 4 MB, not the platform's 25: with the presentation's 25 the two
            // still fit one 30 MB request together (D-121, assumption 3).
            'max_kilobytes' => 4096,
            'max_files' => 1,
        ],
        ['key' => 'description', 'type' => SubmissionFieldType::Textarea->value, 'required' => false],
    ];

    /** Fallback for `athar.uploads.max_request_kilobytes` (deploy/README.md §2.4: post_max_size 30M). */
    public const DEFAULT_MAX_REQUEST_KILOBYTES = 30720;

    // ----------------------------------------------------------- the default set

    /**
     * Give a project the default fields — only when it has none, so a second
     * call, or a project the administrator already shaped, is left alone.
     *
     * @return int how many fields were written
     */
    public function installDefaults(FinalProject $project): int
    {
        if ($project->fields()->exists()) {
            return 0;
        }

        $rows = self::defaultRows((string) $project->getKey(), Clock::now());

        FinalProjectField::query()->insert(array_values($rows));

        return count($rows);
    }

    /**
     * The default fields as rows for a query-builder insert, keyed by the
     * DEFAULTS `key`. Plain rows rather than models: the D-121 migration
     * writes them through the query builder too, so both paths write the
     * same bytes.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaultRows(string $projectId, CarbonImmutable $at): array
    {
        $rows = [];

        foreach (self::DEFAULTS as $index => $definition) {
            $key = $definition['key'];
            $isFile = $definition['type'] === SubmissionFieldType::File->value;
            $tips = self::lines(__('project.default_fields.'.$key.'.tips'));

            $rows[$key] = [
                'id' => (string) Str::uuid7(),
                'final_project_id' => $projectId,
                'type' => $definition['type'],
                'label' => (string) __('project.default_fields.'.$key.'.label'),
                'description' => self::textOrNull(__('project.default_fields.'.$key.'.description')),
                'tips' => $tips === [] ? null : self::json($tips),
                'is_required' => $definition['required'],
                'accepted_formats' => $isFile ? self::json($definition['formats'] ?? []) : null,
                'max_kilobytes' => $isFile
                    ? min($definition['max_kilobytes'] ?? PHP_INT_MAX, FinalProjectField::platformMaxKilobytes())
                    : null,
                'max_files' => $isFile ? ($definition['max_files'] ?? 1) : null,
                'position' => $index + 1,
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }

        return $rows;
    }

    // --------------------------------------------------------- the stored shape

    /**
     * One entry of `project_submissions.answers` — the only place the shape is
     * spelt out. The field's label and type are COPIED in, never referenced,
     * so the entry still reads the same after the field is edited or removed.
     *
     * @param  array<array-key, array<string, mixed>>  $files  descriptors PrivateFileService::store() returned
     * @return array{field_id: string|null, type: string, label: string, value: string|null, files: list<array<string, mixed>>}
     */
    public static function answer(?string $fieldId, SubmissionFieldType $type, string $label, mixed $value, array $files = []): array
    {
        return [
            'field_id' => $fieldId,
            'type' => $type->value,
            'label' => $label,
            'value' => $type->isFile() ? null : self::textOrNull($value),
            'files' => $type->isFile() ? array_values($files) : [],
        ];
    }

    /**
     * The stored entries, read back. An entry that is not the shape answer()
     * writes is skipped rather than half-read (art. 7); the POSITIONS of the
     * rest are kept, because the download route names a file by them.
     *
     * @return array<int, array{field_id: string|null, type: SubmissionFieldType, label: string, value: string|null, files: list<array<string, mixed>>}>
     */
    public static function read(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $answers = [];

        foreach (array_values($stored) as $position => $entry) {
            $type = is_array($entry) && is_string($entry['type'] ?? null)
                ? SubmissionFieldType::tryFrom($entry['type'])
                : null;

            if (! is_array($entry) || $type === null) {
                continue;
            }

            $files = [];

            if ($type->isFile() && is_array($entry['files'] ?? null)) {
                foreach ($entry['files'] as $file) {
                    if (is_array($file)) {
                        $files[] = $file;
                    }
                }
            }

            $answers[$position] = [
                'field_id' => is_string($entry['field_id'] ?? null) ? $entry['field_id'] : null,
                'type' => $type,
                'label' => is_string($entry['label'] ?? null) ? $entry['label'] : '',
                'value' => $type->isFile() ? null : self::textOrNull($entry['value'] ?? null),
                'files' => $files,
            ];
        }

        return $answers;
    }

    /**
     * An earlier, fixed hand-in (D-110 and before) as entries against the
     * default fields just written for its project — one entry per default
     * field in order, then the pre-D-110 `files` list when it holds anything.
     * Nothing is dropped and no column is read that the row does not have.
     *
     * @param  object  $submission  a `project_submissions` row as the query builder returns it
     * @param  array<string, array<string, mixed>>  $defaultRows  defaultRows() for the row's project
     * @return list<array{field_id: string|null, type: string, label: string, value: string|null, files: list<array<string, mixed>>}>
     */
    public static function answersFromLegacy(object $submission, array $defaultRows): array
    {
        $columns = (array) $submission;
        $answers = [];

        foreach (self::DEFAULTS as $definition) {
            $key = $definition['key'];
            $field = $defaultRows[$key] ?? null;

            if ($field === null) {
                continue;
            }

            $type = SubmissionFieldType::from($definition['type']);
            $legacy = $columns[$key] ?? null;

            $answers[] = self::answer(
                (string) $field['id'],
                $type,
                (string) $field['label'],
                $type->isFile() ? null : $legacy,
                $type->isFile() ? self::legacyFiles($legacy) : [],
            );
        }

        $older = self::legacyFiles($columns['files'] ?? null);

        if ($older !== []) {
            $answers[] = self::answer(
                null,
                SubmissionFieldType::File,
                (string) __('project.legacy_files_label'),
                null,
                $older,
            );
        }

        return $answers;
    }

    // ----------------------------------------------------- the shared ceilings

    /** How many files one hand-in may carry, all upload fields together. */
    public static function maxFilesPerHandIn(): int
    {
        return FinalProjectField::platformMaxFiles();
    }

    /** The heaviest body one request may carry, in kilobytes (BR-36). */
    public static function maxRequestKilobytes(): int
    {
        $configured = config('athar.uploads.max_request_kilobytes');

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_MAX_REQUEST_KILOBYTES;
    }

    /**
     * How many files the given fields ask for together. The field being
     * edited is left out by its id; the caller adds its new number.
     *
     * @param  iterable<FinalProjectField>  $fields
     */
    public static function filesAskedFor(iterable $fields, ?string $exceptId = null): int
    {
        $total = 0;

        foreach ($fields as $field) {
            if ($field->isFile() && (string) $field->getKey() !== $exceptId) {
                $total += $field->maxFiles();
            }
        }

        return $total;
    }

    /**
     * The heaviest hand-in the fields allow, in kilobytes: every upload field
     * filled to its count, every file at its limit.
     *
     * @param  iterable<FinalProjectField>  $fields
     */
    public static function largestHandInKilobytes(iterable $fields): int
    {
        $total = 0;

        foreach ($fields as $field) {
            if ($field->isFile()) {
                $total += $field->maxFiles() * $field->maxKilobytes();
            }
        }

        return $total;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Stored descriptors, from a json string or an array; a single descriptor
     * (D-110's columns) and a list of them (`files`) both read as a list.
     *
     * @return list<array<string, mixed>>
     */
    private static function legacyFiles(mixed $stored): array
    {
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        if (! array_is_list($decoded)) {
            return [$decoded];
        }

        $files = [];

        foreach ($decoded as $file) {
            if (is_array($file) && $file !== []) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private static function textOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return list<string>
     */
    private static function lines(mixed $value): array
    {
        $lines = [];

        foreach (is_array($value) ? $value : [] as $line) {
            $text = self::textOrNull($line);

            if ($text !== null) {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
