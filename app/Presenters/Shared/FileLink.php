<?php

declare(strict_types=1);

namespace App\Presenters\Shared;

use App\Support\ViewModel;

/**
 * One handed-in or attached file, as a screen shows it.
 *
 * Files are stored outside the web root under a random name, so the stored path
 * is never a URL and is never emitted (CONSTITUTION art. 24). A screen may show
 * the original name and the size; the link is a temporary signed URL minted by
 * PrivateFileService after the policy has run.
 *
 * `downloadUrl` is deliberately nullable: there is no signed-download route for
 * a *submission* file on this platform yet, and a link that goes nowhere is
 * worse than no link. The views render the name plainly when it is null.
 *
 * @see PRD §9.11, §12.5 · CONSTITUTION art. 22, art. 24
 */
final class FileLink extends ViewModel
{
    /**
     * @param  array<string, mixed>  $stored  one entry of a `files` JSON column
     */
    public static function fromStored(array $stored, ?string $downloadUrl = null): self
    {
        $name = $stored['original_name'] ?? $stored['name'] ?? null;
        $bytes = $stored['size'] ?? $stored['bytes'] ?? null;

        return new self([
            'name' => is_string($name) && $name !== '' ? $name : '—',
            'sizeLabel' => self::humanSize(is_numeric($bytes) ? (int) $bytes : null),
            'downloadUrl' => $downloadUrl,
        ]);
    }

    /**
     * Every file of a `files` JSON column, each with its signed link when a
     * builder is given (App\Support\SignedFiles, D-80).
     *
     * @param  (callable(int): string)|null  $urlFor
     * @return list<self>
     */
    public static function collection(mixed $files, ?callable $urlFor = null): array
    {
        if (! is_array($files)) {
            return [];
        }

        $links = [];

        foreach (array_values($files) as $index => $entry) {
            if (is_array($entry)) {
                $links[] = self::fromStored($entry, $urlFor === null ? null : $urlFor($index));
            }
        }

        return $links;
    }

    /**
     * A size in Latin numerals with a translated unit — never a hard-coded
     * "MB" string, and never an Arabic word inside a PHP file (art. 15).
     */
    public static function humanSize(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }

        if ($bytes < 1024 * 1024) {
            return __('app.size.kilobytes', ['value' => (string) max(1, (int) round($bytes / 1024))]);
        }

        return __('app.size.megabytes', [
            'value' => rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.'),
        ]);
    }
}
