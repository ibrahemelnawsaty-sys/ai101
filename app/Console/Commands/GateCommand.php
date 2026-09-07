<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Shared machinery for the automated pre-merge gates.
 *
 * Every gate is a read-only filesystem scanner. It never opens a database
 * connection, never boots the queue and never reaches the network, so it runs
 * identically in a pre-commit hook, in CI and on shared hosting.
 *
 * Reporting contract for all gates:
 *   - findings carry a severity: FAIL blocks the merge, WARN does not;
 *   - output is an English-only summary table (offending Arabic text is never
 *     echoed back, only its location and code point);
 *   - exit code 1 when at least one FAIL finding exists, otherwise 0.
 *
 * @see CONSTITUTION.md Article 13 (blacklist) and Article 26 (gates G1-G12)
 * @see PROJECT-CONTRACT.md section 15 (gate command names)
 */
abstract class GateCommand extends Command
{
    public const FAIL = 'FAIL';

    public const WARN = 'WARN';

    /** Files larger than this are skipped: they are build artefacts, not source. */
    public const MAX_FILE_BYTES = 2000000;

    /** Upper bound on rendered table rows, so a broken branch cannot flood the terminal. */
    public const MAX_TABLE_ROWS = 300;

    /**
     * Paths never scanned by any gate: dependencies, caches and build output.
     *
     * @var list<string>
     */
    public const EXCLUDED_PREFIXES = [
        '.git/',
        '.idea/',
        '.vscode/',
        '.phpunit.cache/',
        'vendor/',
        'node_modules/',
        'storage/',
        'bootstrap/cache/',
        'public/build/',
        'public/vendor/',
        'public/hot',
        'coverage/',
    ];

    /**
     * Collected findings.
     *
     * @var list<array{severity: string, file: string, line: int, rule: string, detail: string}>
     */
    protected array $findings = [];

    protected int $filesScanned = 0;

    // ---------------------------------------------------------------- paths

    /**
     * Repository root, always with forward slashes and no trailing slash.
     */
    protected function repoRoot(): string
    {
        $override = $this->hasOption('path') ? $this->option('path') : null;
        $root = is_string($override) && $override !== '' ? $override : base_path();

        return rtrim(str_replace('\\', '/', $root), '/');
    }

    protected function absolute(string $relative): string
    {
        return $this->repoRoot().'/'.ltrim($relative, '/');
    }

    /**
     * Read a repository file. A missing or unreadable file yields an empty string
     * so a gate degrades to "nothing to report" instead of crashing.
     */
    protected function read(string $relative): string
    {
        $contents = @file_get_contents($this->absolute($relative));

        return $contents === false ? '' : $contents;
    }

    protected function exists(string $relative): bool
    {
        return file_exists($this->absolute($relative));
    }

    /**
     * Collect repository-relative file paths under the given directories.
     *
     * @param  list<string>  $directories  repository-relative; '' means the repository root
     * @param  list<string>  $kinds  values returned by self::kindOf()
     * @return list<string>
     */
    protected function collectFiles(array $directories, array $kinds): array
    {
        $found = [];

        foreach ($directories as $directory) {
            $relative = trim(str_replace('\\', '/', $directory), '/');
            $absolute = $relative === '' ? $this->repoRoot() : $this->repoRoot().'/'.$relative;

            if (! is_dir($absolute)) {
                continue;
            }

            $this->walk($absolute, $relative, $kinds, $found);
        }

        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * @param  list<string>  $kinds
     * @param  list<string>  $found
     */
    private function walk(string $absoluteDir, string $relativeDir, array $kinds, array &$found): void
    {
        $entries = @scandir($absoluteDir);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $relative = $relativeDir === '' ? $entry : $relativeDir.'/'.$entry;
            $absolute = $absoluteDir.'/'.$entry;

            if (self::isExcludedPath($relative)) {
                continue;
            }

            if (is_dir($absolute)) {
                $this->walk($absolute, $relative, $kinds, $found);

                continue;
            }

            if (! is_file($absolute)) {
                continue;
            }

            if (! in_array(self::kindOf($relative), $kinds, true)) {
                continue;
            }

            $size = @filesize($absolute);

            if ($size === false || $size > self::MAX_FILE_BYTES) {
                continue;
            }

            $found[] = $relative;
        }
    }

    public static function isExcludedPath(string $relative): bool
    {
        $probe = rtrim(str_replace('\\', '/', $relative), '/').'/';

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($probe, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True for the gate command sources themselves.
     *
     * The gates carry the forbidden patterns as data. Rules that inspect raw
     * (string-preserving) content would therefore match their own regular
     * expression tables. Rules driven by the PHP tokenizer do not need this
     * exemption, because string literals are blanked before matching, so the
     * exemption is applied narrowly and only where it is unavoidable.
     */
    public static function isGateSource(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);

        return str_starts_with($relative, 'app/Console/Commands/Gate')
            && str_ends_with($relative, '.php');
    }

    /**
     * Classify a file by extension.
     */
    public static function kindOf(string $relative): string
    {
        $lower = strtolower($relative);

        return match (true) {
            str_ends_with($lower, '.blade.php') => 'blade',
            str_ends_with($lower, '.php') => 'php',
            str_ends_with($lower, '.css') => 'css',
            str_ends_with($lower, '.js'), str_ends_with($lower, '.mjs'), str_ends_with($lower, '.cjs'), str_ends_with($lower, '.ts') => 'js',
            str_ends_with($lower, '.svg') => 'svg',
            str_ends_with($lower, '.json') => 'json',
            str_ends_with($lower, '.md') => 'md',
            str_ends_with($lower, '.yml'), str_ends_with($lower, '.yaml') => 'yaml',
            str_ends_with($lower, '.sh') => 'sh',
            default => 'other',
        };
    }

    // ----------------------------------------------------------- sanitising

    /**
     * Blank out comments (and optionally string bodies) while preserving byte
     * offsets and line breaks, so a match offset still maps to the real line.
     */
    public static function sanitizeSource(string $contents, string $kind, bool $stripStrings = true): string
    {
        return match ($kind) {
            'php' => self::sanitizePhp($contents, $stripStrings),
            'blade' => self::sanitizeBlade($contents, $stripStrings),
            'css' => self::sanitizeCLike($contents, $stripStrings, false),
            'js' => self::sanitizeCLike($contents, $stripStrings, true),
            default => $contents,
        };
    }

    private static function sanitizePhp(string $contents, bool $stripStrings): string
    {
        $tokens = @token_get_all($contents);

        if ($tokens === []) {
            return $contents;
        }

        $blankable = [T_COMMENT, T_DOC_COMMENT];

        if ($stripStrings) {
            $blankable[] = T_CONSTANT_ENCAPSED_STRING;
            $blankable[] = T_ENCAPSED_AND_WHITESPACE;
        }

        $out = '';

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                $out .= $token;

                continue;
            }

            $out .= in_array($token[0], $blankable, true) ? self::blank($token[1]) : $token[1];
        }

        return $out;
    }

    private static function sanitizeBlade(string $contents, bool $stripStrings): string
    {
        $out = self::blankPattern('/\{\{--.*?--\}\}/s', $contents);
        $out = self::blankPattern('/<!--.*?-->/s', $out);
        $out = self::blankPhpIslandComments($out);

        return $stripStrings ? self::blankStrings($out, false) : $out;
    }

    /**
     * Blank PHP style comments, but only inside @php ... @endphp.
     *
     * Restricting it to the PHP islands keeps "//" inside an href, a Tailwind
     * class or a protocol-relative URL out of reach, while still treating a
     * comment inside a template's PHP block as what it is. Without this, a
     * comment reading "no now(), no new DateTime" is scanned as if it were code.
     */
    private static function blankPhpIslandComments(string $contents): string
    {
        $result = preg_replace_callback(
            '/@php\b.*?(?:@endphp|\z)/s',
            static function (array $match): string {
                $island = self::blankPattern('#/\*.*?\*/#s', (string) $match[0]);

                return self::blankPattern('#(?<![:\'"])//[^\r\n]*#', $island);
            },
            $contents
        );

        return $result ?? $contents;
    }

    private static function sanitizeCLike(string $contents, bool $stripStrings, bool $lineComments): string
    {
        $out = self::blankPattern('#/\*.*?\*/#s', $contents);

        if ($lineComments) {
            $out = self::blankPattern('#(?<![:\'"])//[^\r\n]*#', $out);
        }

        return $stripStrings ? self::blankStrings($out, $lineComments) : $out;
    }

    private static function blankPattern(string $pattern, string $contents): string
    {
        $result = preg_replace_callback(
            $pattern,
            static fn (array $match): string => self::blank((string) $match[0]),
            $contents
        );

        return $result ?? $contents;
    }

    /**
     * Blank the body of quoted strings, keeping the quotes so the surrounding
     * syntax still parses the same way.
     */
    private static function blankStrings(string $contents, bool $backticks): string
    {
        $pattern = $backticks
            ? '/([\'"`])((?:\\\\.|(?!\1)[^\\\\])*)\1/s'
            : '/([\'"])((?:\\\\.|(?!\1)[^\\\\])*)\1/s';

        $result = preg_replace_callback(
            $pattern,
            static fn (array $match): string => (string) $match[1].self::blank((string) $match[2]).(string) $match[1],
            $contents
        );

        return $result ?? $contents;
    }

    /**
     * Replace every byte except CR and LF with a space (byte length preserved).
     */
    private static function blank(string $text): string
    {
        return preg_replace('/[^\r\n]/', ' ', $text) ?? $text;
    }

    // ------------------------------------------------------------ matching

    /**
     * preg_match_all with byte offsets, flattened into a predictable shape.
     *
     * @return list<array{text: string, offset: int, groups: array<int, string>}>
     */
    public static function matches(string $pattern, string $subject): array
    {
        $sets = [];

        if (@preg_match_all($pattern, $subject, $sets, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return [];
        }

        $out = [];

        foreach ($sets as $set) {
            $groups = [];

            foreach ($set as $index => $capture) {
                $groups[(int) $index] = is_array($capture) ? (string) $capture[0] : '';
            }

            $full = $set[0];

            if (! is_array($full)) {
                continue;
            }

            $out[] = [
                'text' => (string) $full[0],
                'offset' => (int) $full[1],
                'groups' => $groups,
            ];
        }

        return $out;
    }

    /**
     * 1-based line number of a byte offset.
     */
    public static function lineAt(string $contents, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }

        $offset = min($offset, strlen($contents));

        return substr_count($contents, "\n", 0, $offset) + 1;
    }

    /**
     * Text of the first argument of a call whose opening parenthesis sits at $offset.
     */
    public static function firstArgument(string $code, int $offset): string
    {
        $length = strlen($code);
        $depth = 0;
        $start = $offset + 1;
        $index = $offset;

        for (; $index < $length; $index++) {
            $character = $code[$index];

            if ($character === '(' || $character === '[' || $character === '{') {
                $depth++;

                continue;
            }

            if ($character === ')' || $character === ']' || $character === '}') {
                $depth--;

                if ($depth <= 0) {
                    break;
                }

                continue;
            }

            if ($character === ',' && $depth === 1) {
                break;
            }
        }

        return substr($code, $start, max(0, $index - $start));
    }

    /**
     * Collapse a source excerpt to one short, English-safe line.
     */
    public static function excerpt(string $text, int $limit = 70): string
    {
        $text = (string) preg_replace('/\s+/', ' ', trim($text));
        $text = (string) preg_replace('/[^\x20-\x7E]/', '.', $text);

        return strlen($text) > $limit ? substr($text, 0, $limit - 3).'...' : $text;
    }

    // ------------------------------------------------------------ findings

    /**
     * @return array{severity: string, line: int, rule: string, detail: string}
     */
    public static function hit(string $severity, int $line, string $rule, string $detail): array
    {
        return ['severity' => $severity, 'line' => $line, 'rule' => $rule, 'detail' => $detail];
    }

    protected function addFinding(string $severity, string $file, int $line, string $rule, string $detail): void
    {
        $this->findings[] = [
            'severity' => $severity,
            'file' => $file,
            'line' => $line,
            'rule' => $rule,
            'detail' => $detail,
        ];
    }

    protected function addFail(string $file, int $line, string $rule, string $detail): void
    {
        $this->addFinding(self::FAIL, $file, $line, $rule, $detail);
    }

    protected function addWarn(string $file, int $line, string $rule, string $detail): void
    {
        $this->addFinding(self::WARN, $file, $line, $rule, $detail);
    }

    /**
     * Merge findings produced by a shared static scanner.
     *
     * @param  list<array{severity: string, line: int, rule: string, detail: string}>  $hits
     */
    protected function absorb(string $file, array $hits): void
    {
        foreach ($hits as $found) {
            $this->addFinding($found['severity'], $file, $found['line'], $found['rule'], $found['detail']);
        }
    }

    protected function errorCount(): int
    {
        return count(array_filter($this->findings, static fn (array $f): bool => $f['severity'] === self::FAIL));
    }

    protected function warningCount(): int
    {
        return count(array_filter($this->findings, static fn (array $f): bool => $f['severity'] === self::WARN));
    }

    // ------------------------------------------------------------ reporting

    /**
     * Render the summary and return the process exit code.
     */
    protected function renderReport(string $gate, string $title, string $criterion): int
    {
        $errors = $this->errorCount();
        $warnings = $this->warningCount();

        usort($this->findings, static function (array $a, array $b): int {
            if ($a['severity'] !== $b['severity']) {
                return $a['severity'] === self::FAIL ? -1 : 1;
            }

            return [$a['file'], $a['line'], $a['rule']] <=> [$b['file'], $b['line'], $b['rule']];
        });

        if ($this->jsonRequested()) {
            $this->line((string) json_encode([
                'gate' => $gate,
                'title' => $title,
                'criterion' => $criterion,
                'files_scanned' => $this->filesScanned,
                'errors' => $errors,
                'warnings' => $warnings,
                'passed' => $errors === 0,
                'findings' => $this->findings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $errors === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line(str_repeat('=', 96));
        $this->line(sprintf('%-6s %s', $gate, $title));
        $this->line(str_repeat('=', 96));
        $this->line(sprintf('  criterion     : %s', $criterion));
        $this->line(sprintf('  files scanned : %d', $this->filesScanned));
        $this->line(sprintf('  errors (FAIL) : %d', $errors));
        $this->line(sprintf('  warnings      : %d', $warnings));

        if ($this->findings !== []) {
            $rows = [];
            $number = 0;

            foreach ($this->findings as $found) {
                $number++;

                if ($number > self::MAX_TABLE_ROWS) {
                    break;
                }

                $rows[] = [
                    (string) $number,
                    $found['severity'],
                    $found['file'].':'.$found['line'],
                    $found['rule'],
                    self::excerpt($found['detail'], 58),
                ];
            }

            $this->newLine();
            $this->table(['#', 'SEVERITY', 'LOCATION', 'RULE', 'DETAIL'], $rows);

            if (count($this->findings) > self::MAX_TABLE_ROWS) {
                $this->line(sprintf(
                    '  ... %d more finding(s) not shown. Re-run with --json for the full list.',
                    count($this->findings) - self::MAX_TABLE_ROWS
                ));
            }
        }

        $this->newLine();

        if ($errors === 0) {
            $this->line(sprintf('  RESULT: PASS  (%s)', $gate));
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line(sprintf('  RESULT: FAIL  (%s) - merge is blocked until every FAIL row is cleared.', $gate));
        $this->newLine();

        return self::FAILURE;
    }

    protected function jsonRequested(): bool
    {
        return $this->hasOption('json') && $this->option('json') === true;
    }
}
