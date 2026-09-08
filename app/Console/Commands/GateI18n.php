<?php

declare(strict_types=1);

namespace App\Console\Commands;

/**
 * Gate G5 - every user-facing string lives in lang/.
 *
 * Three checks, one command:
 *   1. FAIL: Arabic script inside .php or .blade.php outside the allowed roots.
 *      The rule code says where it sat - I18N-ARABIC for code and strings, which
 *      belong in lang/ar, and I18N-ARABIC-COMMENT for a comment or a docblock,
 *      which is written in English. Both block the merge; Article 13 item 3 bans
 *      Arabic in the file, not merely in its user-facing strings.
 *   2. WARN: keys present in lang/ar with no counterpart in lang/en.
 *   3. WARN: __('file.key') references whose key does not exist in lang/ar.
 *
 * The report never echoes the offending Arabic text back: it prints the file,
 * the line, how many Arabic characters sit there and the code point of the
 * first one, which is enough to find it and keeps gate output ASCII-only.
 *
 * @see CONSTITUTION.md Article 13 item 3, Article 15
 * @see PROJECT-CONTRACT.md section 13
 */
final class GateI18n extends GateCommand
{
    protected $signature = 'gate:i18n
        {--json : Emit findings as JSON instead of a table}
        {--path= : Scan a different repository root}';

    protected $description = 'G5: no Arabic text outside lang/, and lang/en tracks lang/ar.';

    /**
     * Roots where Arabic is legitimate.
     *
     * lang/ holds the strings themselves; seeders hold demo content; tests
     * assert on Arabic copy; docs are the requirement sources.
     *
     * @var list<string>
     */
    public const ALLOWED_PREFIXES = ['lang/', 'database/seeders/', 'tests/', 'docs/'];

    /** Arabic, Arabic Supplement, Extended-A and the presentation forms. */
    public const ARABIC_PATTERN = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]+/u';

    /** @var list<string> */
    private const SCAN_DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'tests'];

    /** Translation calls whose first argument is a literal key. */
    public const TRANSLATION_CALL_PATTERN = '/(?<![\w$>])(?:__|@lang|trans|trans_choice)\s*\(\s*([\'"])([A-Za-z0-9_.\-]+)\1/';

    public function handle(): int
    {
        $this->scanArabic();
        $this->compareLocales();
        $this->checkMissingKeys();

        return $this->renderReport('G5', 'I18N - Arabic lives in lang/ only', 'zero Arabic characters in code; lang/en tracked as a warning');
    }

    private function scanArabic(): void
    {
        foreach ($this->collectFiles(self::SCAN_DIRECTORIES, ['php', 'blade']) as $file) {
            if (self::isAllowedFile($file)) {
                continue;
            }

            $this->filesScanned++;
            $this->absorb($file, self::arabicViolations($file, $this->read($file)));
        }
    }

    public static function isAllowedFile(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Shared scanner, also consumed by gate:forbidden (Article 13 item 3).
     *
     * @return list<array{severity: string, line: int, rule: string, detail: string}>
     */
    public static function arabicViolations(string $relative, string $contents): array
    {
        $kind = self::kindOf($relative);

        if (self::isAllowedFile($relative) || $contents === '' || ! in_array($kind, ['php', 'blade'], true)) {
            return [];
        }

        $found = [];
        $reportedLines = [];

        // Comments blanked, byte layout preserved: a blanked byte at the same
        // offset means the Arabic sat inside a comment, not inside a string.
        $withoutComments = self::sanitizeSource($contents, $kind, false);

        foreach (self::matches(self::ARABIC_PATTERN, $contents) as $match) {
            $line = self::lineAt($contents, $match['offset']);

            if (isset($reportedLines[$line])) {
                continue;
            }

            $reportedLines[$line] = true;

            $inComment = ($withoutComments[$match['offset']] ?? '') === ' '
                && substr($contents, $match['offset'], 1) !== ' ';

            $found[] = self::hit(
                self::FAIL,
                $line,
                $inComment ? 'I18N-ARABIC-COMMENT' : 'I18N-ARABIC',
                sprintf(
                    $inComment
                        ? 'Arabic inside a comment (%d char(s), first is %s); comments are written in English'
                        : 'Arabic text in code (%d char(s), first is %s); move it to lang/ar and call __()',
                    self::characterCount($match['text']),
                    self::firstCodePoint($match['text']),
                ),
            );
        }

        return $found;
    }

    /**
     * lang/ar is the reference. A key it defines with no counterpart in lang/en
     * is a warning, never a merge blocker: full English is out of scope for the
     * first release (PROJECT-CONTRACT section 13).
     */
    private function compareLocales(): void
    {
        $arabicFiles = $this->collectFiles(['lang/ar'], ['php']);

        if ($arabicFiles === []) {
            $this->addWarn('lang/ar', 0, 'I18N-NO-SOURCE', 'lang/ar holds no translation file yet');

            return;
        }

        foreach ($arabicFiles as $file) {
            $this->filesScanned++;
            $basename = basename($file);
            $englishFile = 'lang/en/'.$basename;

            $arabic = $this->loadTranslations($file);

            if ($arabic === null) {
                $this->addWarn($file, 0, 'I18N-UNREADABLE', 'translation file does not return an array');

                continue;
            }

            if (! $this->exists($englishFile)) {
                $this->addWarn(
                    $englishFile,
                    0,
                    'I18N-EN-FILE-MISSING',
                    sprintf('no English counterpart for lang/ar/%s (%d key(s) untranslated)', $basename, count($arabic)),
                );

                continue;
            }

            $english = $this->loadTranslations($englishFile);

            if ($english === null) {
                $this->addWarn($englishFile, 0, 'I18N-UNREADABLE', 'translation file does not return an array');

                continue;
            }

            $missing = array_keys(array_diff_key($arabic, $english));
            $orphans = array_keys(array_diff_key($english, $arabic));

            if ($missing !== []) {
                $this->addWarn(
                    $englishFile,
                    0,
                    'I18N-EN-KEY-MISSING',
                    sprintf('%d key(s) missing, e.g. %s', count($missing), implode(', ', array_slice($missing, 0, 3))),
                );
            }

            if ($orphans !== []) {
                $this->addWarn(
                    $englishFile,
                    0,
                    'I18N-EN-KEY-ORPHAN',
                    sprintf('%d key(s) absent from lang/ar, e.g. %s', count($orphans), implode(', ', array_slice($orphans, 0, 3))),
                );
            }
        }
    }

    /**
     * Literal translation keys referenced by code but absent from lang/ar.
     * A warning, because keys can legitimately be built at runtime.
     */
    private function checkMissingKeys(): void
    {
        $catalogue = [];

        foreach ($this->collectFiles(['lang/ar'], ['php']) as $file) {
            $group = basename($file, '.php');
            $translations = $this->loadTranslations($file);

            if ($translations === null) {
                continue;
            }

            foreach (array_keys($translations) as $key) {
                $catalogue[$group.'.'.$key] = true;
            }
        }

        if ($catalogue === []) {
            return;
        }

        $groups = [];

        foreach (array_keys($catalogue) as $key) {
            $groups[substr($key, 0, (int) strpos($key, '.'))] = true;
        }

        foreach ($this->collectFiles(self::SCAN_DIRECTORIES, ['php', 'blade']) as $file) {
            if (self::isGateSource($file) || str_starts_with($file, 'lang/')) {
                continue;
            }

            $contents = $this->read($file);

            foreach (self::matches(self::TRANSLATION_CALL_PATTERN, $contents) as $match) {
                $key = $match['groups'][2] ?? '';

                if ($key === '' || ! str_contains($key, '.')) {
                    continue;
                }

                // __('enums.user_role.'.$this->value) is a prefix, not a key.
                // Both spellings are skipped: the trailing dot, and a literal
                // immediately followed by a concatenation.
                $after = ltrim(substr($contents, $match['offset'] + strlen($match['text']), 8));

                if (str_ends_with($key, '.') || str_starts_with($after, '.')) {
                    continue;
                }

                $group = substr($key, 0, (int) strpos($key, '.'));

                // Only judge groups that exist: a validation override or a
                // package group is not this gate's business.
                if (! isset($groups[$group]) || isset($catalogue[$key])) {
                    continue;
                }

                $this->addWarn(
                    $file,
                    self::lineAt($contents, $match['offset']),
                    'I18N-KEY-UNKNOWN',
                    sprintf('translation key "%s" is not defined in lang/ar/%s.php', $key, $group),
                );
            }
        }
    }

    /**
     * Load a translation file and flatten it to dot notation.
     *
     * @return array<string, string>|null
     */
    private function loadTranslations(string $relative): ?array
    {
        $absolute = $this->absolute($relative);

        if (! is_file($absolute)) {
            return null;
        }

        $loader = static function (string $file): mixed {
            return require $file;
        };

        try {
            $value = $loader($absolute);
        } catch (\Throwable) {
            return null;
        }

        return is_array($value) ? $this->flatten($value) : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = is_scalar($value) ? (string) $value : '';
        }

        return $flat;
    }

    private static function characterCount(string $text): int
    {
        $count = @preg_match_all('/./u', $text);

        return $count === false ? strlen($text) : $count;
    }

    /**
     * Code point of the first character, as U+XXXX, so the report stays ASCII.
     */
    private static function firstCodePoint(string $text): string
    {
        $codes = @unpack('N*', (string) mb_convert_encoding(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-32BE', 'UTF-8'));

        if ($codes === false || $codes === []) {
            return 'U+????';
        }

        return sprintf('U+%04X', (int) reset($codes));
    }
}
