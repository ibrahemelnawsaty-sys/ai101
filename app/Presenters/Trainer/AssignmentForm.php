<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Models\Assignment;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\ViewModel;

/**
 * The assignment editor, open on a new task or an existing one.
 *
 * `dueAtValue` is Riyadh wall time, because that is what the trainer types and
 * what the FormRequest turns back into a UTC instant through Clock (art. 11).
 * The upload limits are shown in megabytes, which is how the field is labelled
 * and how the FormRequest now reads them.
 *
 * @see BR-07, BR-11, BR-23 · PRD §9.11.3 · CONSTITUTION art. 11
 */
final class AssignmentForm extends ViewModel
{
    use PresentsFormValues;

    public static function blank(?string $weekId = null): self
    {
        return new self([
            'exists' => false,
            'id' => null,
            'title' => '',
            'description' => '',
            'weekId' => $weekId,
            'maxScore' => '',
            'dueAtValue' => '',
            'maxFiles' => (int) config('athar.uploads.max_files', 5),
            'maxFileMb' => max(1, (int) round((int) config('athar.uploads.max_kilobytes', 25600) / 1024)),
            'isMandatory' => true,
            'allowLate' => false,
            'showGithubField' => false,
        ]);
    }

    public static function from(Assignment $assignment): self
    {
        $megabytes = $assignment->getAttribute('max_file_size_mb');
        $files = $assignment->getAttribute('max_files');

        return new self([
            'exists' => true,
            'id' => (string) $assignment->getKey(),
            'title' => (string) $assignment->getAttribute('title'),
            'description' => (string) ($assignment->getAttribute('description') ?? ''),
            'weekId' => $assignment->getAttribute('week_id') === null
                ? null
                : (string) $assignment->getAttribute('week_id'),
            'maxScore' => (float) $assignment->getAttribute('max_score'),
            'dueAtValue' => self::dateTimeInput($assignment->getAttribute('due_at')),
            'maxFiles' => $files === null ? (int) config('athar.uploads.max_files', 5) : (int) $files,
            'maxFileMb' => $megabytes === null
                ? max(1, (int) round((int) config('athar.uploads.max_kilobytes', 25600) / 1024))
                : (int) $megabytes,
            'isMandatory' => (bool) $assignment->getAttribute('is_mandatory'),
            'allowLate' => (bool) $assignment->getAttribute('allow_late'),
            'showGithubField' => (bool) $assignment->getAttribute('allow_github_link'),
        ]);
    }
}
