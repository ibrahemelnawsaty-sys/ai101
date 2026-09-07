<?php

declare(strict_types=1);

namespace App\Console\Commands;

/**
 * Gate G12 - nothing is built that the matrix does not track.
 *
 * The gate walks the repository, harvests every requirement identifier a human
 * wrote down as evidence of intent - in a docblock, in an inline comment, in a
 * test name - and then proves that each one owns a row in the live traceability
 * matrix. An identifier with no row means one of two things, and both block the
 * merge: either the matrix was not updated in the same commit (Article 25), or
 * the code cites an identifier the requirements do not define.
 *
 * Identifier shapes recognised, matching the matrix itself:
 *   BR-01 .. BR-36        business rules
 *   AC-01 ..              final acceptance criteria
 *   FR-<MODULE>-<NN>      functional requirements, e.g. FR-ATT-07
 *   NFR-<MODULE>-<NN>     non functional requirements, e.g. NFR-SEC-04
 *
 * Product decisions (D-NN) are not requirements and have no matrix row; they are
 * checked against the decisions log instead, and only as a warning, because a
 * decision may legitimately be raised in the same commit that cites it.
 *
 * The gate reads files only. It opens no database connection, so it runs in a
 * pre-commit hook and on shared hosting exactly as it runs in CI.
 *
 * @see CONSTITUTION.md Article 25, Article 26 gate G12
 * @see PROJECT-CONTRACT.md section 14
 */
final class GateTraceability extends GateCommand
{
    protected $signature = 'gate:traceability
        {--json : Emit findings as JSON instead of a table}
        {--list : Print the full inventory of harvested identifiers}
        {--path= : Scan a different repository root}';

    protected $description = 'G12: every requirement identifier cited in code has a row in TRACEABILITY.md.';

    /** The live traceability matrix - the only accepted proof of tracking. */
    public const MATRIX_FILE = 'docs/02-plan/TRACEABILITY.md';

    /** The decisions log, used for the D-NN warning only. */
    public const DECISIONS_FILE = 'docs/03-decisions/DECISIONS.md';

    /** @var list<string> */
    private const SCAN_DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'tests'];

    /**
     * Directories whose files are feature code and must therefore name at least
     * one requirement identifier in their header comment (Article 25 item 1).
     *
     * @var list<string>
     */
    private const FEATURE_DIRECTORIES = [
        'app/Services/',
        'app/Http/Controllers/',
        'app/Http/Requests/',
        'app/Http/Middleware/',
        'app/Policies/',
        'app/Jobs/',
    ];

    /** BR-01 / AC-18 / FR-ATT-07 / NFR-SEC-04, anywhere inside a line. */
    public const REQUIREMENT_PATTERN = '/\b((?:N?FR-[A-Z][A-Z0-9]{1,15})|BR|AC)-(\d{1,3})\b/';

    /** The same shape, anchored, for judging a single table cell. */
    public const REQUIREMENT_CELL_PATTERN = '/^((?:N?FR-[A-Z][A-Z0-9]{1,15})|BR|AC)-(\d{1,3})$/';

    /** Product decision reference. */
    public const DECISION_PATTERN = '/\bD-(\d{1,3})\b/';

    public const DECISION_CELL_PATTERN = '/^D-(\d{1,3})/';

    /** Closure based test declarations: it('BR-03: ...'). */
    private const TEST_TITLE_PATTERN = '/(?<![\w$>])(?:it|test|describe)\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/';

    /** Class based test methods: public function test_br_03_...(). */
    private const TEST_METHOD_PATTERN = '/\bfunction\s+(test[A-Za-z0-9_]*)/i';

    /**
     * Every identifier harvested from the code, with where it was first seen.
     *
     * @var array<string, array{file: string, line: int, count: int}>
     */
    private array $referenced = [];

    /**
     * Decision identifiers harvested from the code.
     *
     * @var array<string, array{file: string, line: int, count: int}>
     */
    private array $decisions = [];

    public function handle(): int
    {
        $matrix = $this->matrixRows();

        if ($matrix === null) {
            $this->addFail(
                self::MATRIX_FILE,
                0,
                'TRACE-NO-MATRIX',
                'the traceability matrix is missing; no requirement can be proven tracked'
            );

            return $this->renderReport('G12', 'TRACEABILITY - every identifier has a row', 'the matrix must exist and cover every cited identifier');
        }

        $decisionRows = $this->decisionRows();

        $this->harvest();
        $this->judgeRequirements($matrix);
        $this->judgeDecisions($decisionRows);
        $this->renderInventory($matrix);

        return $this->renderReport(
            'G12',
            'TRACEABILITY - every identifier has a row',
            'zero requirement identifiers cited in code without a row in '.self::MATRIX_FILE
        );
    }

    // ------------------------------------------------------------ harvest

    /**
     * Collect identifiers from comments everywhere, and additionally from test
     * names under tests/, where the name is the constitutional evidence.
     */
    private function harvest(): void
    {
        foreach ($this->collectFiles(self::SCAN_DIRECTORIES, ['php', 'blade']) as $file) {
            if (self::isGateSource($file)) {
                continue;
            }

            $this->filesScanned++;
            $contents = $this->read($file);

            if ($contents === '') {
                continue;
            }

            $comments = self::commentsOnly($contents, self::kindOf($file));

            $this->harvestFrom($file, $comments, 0);

            if (str_starts_with($file, 'tests/')) {
                foreach ($this->testNames($contents) as $name) {
                    $this->harvestFrom($file, $name['name'], $name['line']);
                }
            }

            $this->checkFeatureHeader($file, $comments);
        }
    }

    /**
     * Record every identifier inside a chunk of text.
     *
     * When $fixedLine is zero the line is derived from the byte offset, which is
     * accurate because commentsOnly() preserves the original byte layout.
     */
    private function harvestFrom(string $file, string $text, int $fixedLine): void
    {
        if ($text === '') {
            return;
        }

        foreach (self::matches(self::REQUIREMENT_PATTERN, $text) as $match) {
            $identifier = self::normalise($match['groups'][1] ?? '', $match['groups'][2] ?? '');

            if ($identifier === null) {
                continue;
            }

            $line = $fixedLine > 0 ? $fixedLine : self::lineAt($text, $match['offset']);

            if (isset($this->referenced[$identifier])) {
                $this->referenced[$identifier]['count']++;

                continue;
            }

            $this->referenced[$identifier] = ['file' => $file, 'line' => $line, 'count' => 1];
        }

        foreach (self::matches(self::DECISION_PATTERN, $text) as $match) {
            $identifier = self::normalise('D', $match['groups'][1] ?? '');

            if ($identifier === null) {
                continue;
            }

            $line = $fixedLine > 0 ? $fixedLine : self::lineAt($text, $match['offset']);

            if (isset($this->decisions[$identifier])) {
                $this->decisions[$identifier]['count']++;

                continue;
            }

            $this->decisions[$identifier] = ['file' => $file, 'line' => $line, 'count' => 1];
        }
    }

    /**
     * Test names declared in a file, with the line each sits on.
     *
     * @return list<array{name: string, line: int}>
     */
    private function testNames(string $contents): array
    {
        $names = [];

        foreach (self::matches(self::TEST_TITLE_PATTERN, $contents) as $match) {
            $names[] = [
                'name' => $match['groups'][2] ?? '',
                'line' => self::lineAt($contents, $match['offset']),
            ];
        }

        foreach (self::matches(self::TEST_METHOD_PATTERN, $contents) as $match) {
            $names[] = [
                'name' => str_replace('_', '-', $match['groups'][1] ?? ''),
                'line' => self::lineAt($contents, $match['offset']),
            ];
        }

        return $names;
    }

    /**
     * Article 25 item 1: a feature file states the requirements it serves.
     *
     * A warning, not a blocker: the constitutional blocker is the missing row,
     * and a file may legitimately be pure plumbing inside a feature directory.
     */
    private function checkFeatureHeader(string $file, string $comments): void
    {
        $isFeature = false;

        foreach (self::FEATURE_DIRECTORIES as $prefix) {
            if (str_starts_with($file, $prefix)) {
                $isFeature = true;

                break;
            }
        }

        if (! $isFeature || $comments === '') {
            return;
        }

        if (self::matches(self::REQUIREMENT_PATTERN, $comments) !== []) {
            return;
        }

        $this->addWarn(
            $file,
            1,
            'TRACE-NO-HEADER-ID',
            'feature file names no requirement identifier in its header comment'
        );
    }

    // ------------------------------------------------------------ judging

    /**
     * @param  array<string, int>  $matrix  identifier => line of its row
     */
    private function judgeRequirements(array $matrix): void
    {
        foreach ($this->referenced as $identifier => $origin) {
            if (isset($matrix[$identifier])) {
                continue;
            }

            $this->addFail(
                $origin['file'],
                $origin['line'],
                'TRACE-NO-ROW',
                $identifier.' has no row in '.self::MATRIX_FILE.'; update the matrix in this same commit'
            );
        }
    }

    /**
     * @param  array<string, int>  $rows  identifier => line of its row
     */
    private function judgeDecisions(array $rows): void
    {
        if ($rows === []) {
            if ($this->decisions !== []) {
                $this->addWarn(
                    self::DECISIONS_FILE,
                    0,
                    'TRACE-NO-DECISIONS',
                    'the decisions log could not be read, so decision references are unverified'
                );
            }

            return;
        }

        foreach ($this->decisions as $identifier => $origin) {
            if (isset($rows[$identifier])) {
                continue;
            }

            $this->addWarn(
                $origin['file'],
                $origin['line'],
                'TRACE-NO-DECISION',
                $identifier.' is cited but has no entry in '.self::DECISIONS_FILE
            );
        }
    }

    // ------------------------------------------------------------- matrix

    /**
     * Identifiers that own a row in the matrix.
     *
     * Only the first cell of a table row counts. A mention in prose is not a
     * row, and the constitution asks for a row.
     *
     * @return array<string, int>|null null when the matrix cannot be read
     */
    private function matrixRows(): ?array
    {
        if (! $this->exists(self::MATRIX_FILE)) {
            return null;
        }

        $contents = $this->read(self::MATRIX_FILE);

        if ($contents === '') {
            return null;
        }

        $rows = [];

        foreach (self::firstCells($contents) as $cell) {
            $matched = [];

            if (preg_match(self::REQUIREMENT_CELL_PATTERN, $cell['text'], $matched) !== 1) {
                continue;
            }

            $identifier = self::normalise($matched[1], $matched[2]);

            if ($identifier === null || isset($rows[$identifier])) {
                continue;
            }

            $rows[$identifier] = $cell['line'];
        }

        return $rows;
    }

    /**
     * Decision identifiers that own a row in the decisions log.
     *
     * @return array<string, int>
     */
    private function decisionRows(): array
    {
        if (! $this->exists(self::DECISIONS_FILE)) {
            return [];
        }

        $contents = $this->read(self::DECISIONS_FILE);

        if ($contents === '') {
            return [];
        }

        $rows = [];

        foreach (self::firstCells($contents) as $cell) {
            $matched = [];

            if (preg_match(self::DECISION_CELL_PATTERN, $cell['text'], $matched) !== 1) {
                continue;
            }

            $identifier = self::normalise('D', $matched[1]);

            if ($identifier === null || isset($rows[$identifier])) {
                continue;
            }

            $rows[$identifier] = $cell['line'];
        }

        // Headings are an accepted form for an escalation entry (Article 31).
        foreach (self::matches('/^#{2,4}\s+`?(D-\d{1,3})/m', $contents) as $match) {
            $raw = $match['groups'][1] ?? '';
            $identifier = self::normalise('D', substr($raw, 2));

            if ($identifier === null || isset($rows[$identifier])) {
                continue;
            }

            $rows[$identifier] = self::lineAt($contents, $match['offset']);
        }

        return $rows;
    }

    /**
     * First cell of every Markdown table row, cleaned of decoration.
     *
     * @return list<array{text: string, line: int}>
     */
    private static function firstCells(string $contents): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents);

        if ($lines === false) {
            return [];
        }

        $cells = [];

        foreach ($lines as $index => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || $trimmed[0] !== '|') {
                continue;
            }

            $parts = explode('|', $trimmed);

            if (! isset($parts[1])) {
                continue;
            }

            $text = trim(str_replace(['`', '*', '_', '~'], '', $parts[1]));

            if ($text === '') {
                continue;
            }

            $cells[] = ['text' => $text, 'line' => $index + 1];
        }

        return $cells;
    }

    // ---------------------------------------------------------- inventory

    /**
     * @param  array<string, int>  $matrix
     */
    private function renderInventory(array $matrix): void
    {
        if ($this->jsonRequested()) {
            return;
        }

        $identifiers = array_keys($this->referenced);
        sort($identifiers);

        $traced = 0;
        $families = [];

        foreach ($identifiers as $identifier) {
            if (isset($matrix[$identifier])) {
                $traced++;
            }

            $family = self::family($identifier);
            $families[$family] = ($families[$family] ?? 0) + 1;
        }

        ksort($families);

        $this->newLine();
        $this->line(sprintf('  matrix rows   : %d identifier(s) tracked in %s', count($matrix), self::MATRIX_FILE));
        $this->line(sprintf('  cited in code : %d distinct identifier(s)', count($identifiers)));
        $this->line(sprintf('  with a row    : %d', $traced));
        $this->line(sprintf('  decisions     : %d distinct D-NN reference(s)', count($this->decisions)));

        if ($families !== []) {
            $summary = [];

            foreach ($families as $family => $count) {
                $summary[] = $family.'='.$count;
            }

            $this->line('  by family     : '.implode('  ', $summary));
        }

        if ($this->option('list') === true && $identifiers !== []) {
            $this->newLine();
            $rows = [];

            foreach ($identifiers as $identifier) {
                $origin = $this->referenced[$identifier];

                $rows[] = [
                    $identifier,
                    isset($matrix[$identifier]) ? 'row '.$matrix[$identifier] : 'NO ROW',
                    (string) $origin['count'],
                    $origin['file'].':'.$origin['line'],
                ];
            }

            $this->table(['IDENTIFIER', 'MATRIX', 'CITES', 'FIRST CITED AT'], $rows);
        }
    }

    // ------------------------------------------------------------ helpers

    /**
     * Normalise an identifier to the matrix spelling: two digit, upper case.
     */
    public static function normalise(string $prefix, string $digits): ?string
    {
        $prefix = strtoupper(trim($prefix));
        $digits = ltrim(trim($digits), '0');

        if ($prefix === '' || $digits === '') {
            // "BR-00" is not a requirement, and neither is an empty prefix.
            return null;
        }

        if (! ctype_digit($digits)) {
            return null;
        }

        return $prefix.'-'.str_pad($digits, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Family of an identifier: BR, AC, FR-ATT, NFR-SEC.
     */
    public static function family(string $identifier): string
    {
        $position = strrpos($identifier, '-');

        return $position === false ? $identifier : substr($identifier, 0, $position);
    }

    /**
     * A copy of the source in which everything except comment text is blanked,
     * byte for byte, so an offset inside the result still maps to the real line.
     */
    public static function commentsOnly(string $contents, string $kind): string
    {
        return $kind === 'blade'
            ? self::bladeCommentsOnly($contents)
            : self::phpCommentsOnly($contents);
    }

    private static function phpCommentsOnly(string $contents): string
    {
        $tokens = @token_get_all($contents);

        if ($tokens === []) {
            return self::blankOut($contents);
        }

        $out = '';

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                $out .= self::blankOut((string) $token);

                continue;
            }

            $text = (string) $token[1];
            $out .= in_array((int) $token[0], [T_COMMENT, T_DOC_COMMENT], true)
                ? $text
                : self::blankOut($text);
        }

        return $out;
    }

    /**
     * Blade carries both its own comments and any PHP comment inside a directive
     * or an echo. Keeping the Blade comment regions and the PHP style comments
     * is enough: nothing else in a template is a place to cite a requirement.
     */
    private static function bladeCommentsOnly(string $contents): string
    {
        $keep = self::blankOut($contents);

        $patterns = [
            '/\{\{--.*?--\}\}/s',
            '/<!--.*?-->/s',
            '#/\*.*?\*/#s',
            '#(?<![:\'"])//[^\r\n]*#',
        ];

        foreach ($patterns as $pattern) {
            foreach (self::matches($pattern, $contents) as $match) {
                $keep = substr_replace($keep, $match['text'], $match['offset'], strlen($match['text']));
            }
        }

        return $keep;
    }

    /**
     * Replace every byte except CR and LF with a space, preserving byte length.
     */
    private static function blankOut(string $text): string
    {
        return preg_replace('/[^\r\n]/', ' ', $text) ?? $text;
    }
}
