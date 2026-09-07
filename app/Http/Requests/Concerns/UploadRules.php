<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Upload constraints shared by every endpoint that accepts a file.
 *
 * The extension allow-list is read from config, never hard-coded: PRD §9.11.2
 * says "any format" while §12.5 requires executables to be refused, and the
 * reconciliation between the two is OPEN as D-17 in DECISIONS.md (D-18 is the
 * Zoom integration, not the allow-list). Reading it from config means settling
 * D-17 is a configuration change, not a code change,
 * and the deny-nothing default is impossible to reach by accident.
 *
 * This trait checks the declared extension and the size only. The real control
 * — sniffing the type from the file's own bytes — belongs to
 * App\Services\Storage\FileGuard and runs after validation (PRD §12.5).
 *
 * @see PRD §9.11.2, §9.12, §12.5 · DECISIONS D-17 · CONSTITUTION Art. 24
 */
trait UploadRules
{
    /** Default per-file ceiling in kilobytes (25 MB, PRD §9.11.2). */
    protected const DEFAULT_MAX_KILOBYTES = 25600;

    /** Default maximum number of files per submission (PRD §9.11.2). */
    protected const DEFAULT_MAX_FILES = 5;

    /** @return list<string> */
    protected function allowedExtensions(): array
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('athar.uploads.allowed_extensions', [
            'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
            'txt', 'md', 'csv', 'zip', 'png', 'jpg', 'jpeg', 'webp',
        ]);

        return array_values(array_unique(array_map('strtolower', $allowed)));
    }

    protected function maxKilobytes(?int $override = null): int
    {
        $configured = (int) config('athar.uploads.max_kilobytes', self::DEFAULT_MAX_KILOBYTES);
        $limit = $override !== null && $override > 0 ? $override : $configured;

        return min($limit, $configured);
    }

    protected function maxFiles(?int $override = null): int
    {
        $configured = (int) config('athar.uploads.max_files', self::DEFAULT_MAX_FILES);
        $limit = $override !== null && $override > 0 ? $override : $configured;

        return min($limit, $configured);
    }

    /**
     * @return list<string>
     */
    protected function fileRules(?int $maxKilobytes = null): array
    {
        return [
            'file',
            'extensions:'.implode(',', $this->allowedExtensions()),
            'max:'.$this->maxKilobytes($maxKilobytes),
        ];
    }

    /**
     * @return list<string>
     */
    protected function fileArrayRules(?int $maxFiles = null): array
    {
        return ['array', 'max:'.$this->maxFiles($maxFiles)];
    }

    /** @return list<string> */
    protected function githubUrlRules(): array
    {
        return ['nullable', 'string', 'url:https', 'max:255', 'starts_with:https://github.com/'];
    }
}
