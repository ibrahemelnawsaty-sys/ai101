<?php

declare(strict_types=1);

namespace App\Console\Commands;

/**
 * Gate G10 - every business rule carries its own test.
 *
 * A rule with no test is a rule that is not implemented, whatever the code
 * says. This gate asserts that each of the thirty six business rules appears in
 * the NAME of at least one test under tests/. A group attribute, a docblock or
 * a comment is deliberately not accepted as evidence: the name is what a failing
 * run prints, and the name is what the constitution requires.
 *
 * Accepted shapes:
 *   it('BR-03: check in after the late boundary is late', ...)
 *   test('BR-03 ...', ...)
 *   describe('BR-03 ...', ...)
 *   public function test_br_03_check_in_after_the_late_boundary(): void
 *
 * @see CONSTITUTION.md Article 21, Article 25, Article 26 gate G10
 * @see PROJECT-CONTRACT.md section 14
 */
final class GateBusinessRules extends GateCommand
{
    protected $signature = 'gate:br
        {--json : Emit findings as JSON instead of a table}
        {--path= : Scan a different repository root}';

    protected $description = 'G10: every business rule has at least one test carrying its identifier.';

    public const RULE_PREFIX = 'BR';

    public const FIRST_RULE = 1;

    public const LAST_RULE = 36;

    /** @var list<string> */
    private const TEST_DIRECTORIES = ['tests'];

    /** Closure based test declarations. */
    private const TEST_TITLE_PATTERN = '/(?<![\w$>])(?:it|test|describe)\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/';

    /** Class based test methods. */
    private const TEST_METHOD_PATTERN = '/\bfunction\s+(test[A-Za-z0-9_]*)/i';

    public function handle(): int
    {
        $coverage = [];
        $strays = [];

        foreach ($this->collectFiles(self::TEST_DIRECTORIES, ['php']) as $file) {
            $this->filesScanned++;
            $contents = $this->read($file);

            foreach ($this->testNames($contents) as $entry) {
                foreach (self::identifiersIn($entry['name']) as $identifier) {
                    $number = (int) substr($identifier, 3);

                    if ($number < self::FIRST_RULE || $number > self::LAST_RULE) {
                        $strays[] = ['id' => $identifier, 'file' => $file, 'line' => $entry['line']];

                        continue;
                    }

                    $coverage[$identifier] ??= $file.':'.$entry['line'];
                }
            }
        }

        $missing = [];

        for ($number = self::FIRST_RULE; $number <= self::LAST_RULE; $number++) {
            $identifier = self::identifier($number);

            if (! isset($coverage[$identifier])) {
                $missing[] = $identifier;

                $this->addFail(
                    'tests/Feature/BusinessRules',
                    0,
                    'BR-UNTESTED',
                    $identifier.' appears in no test name; an untested rule is an unimplemented rule'
                );
            }
        }

        foreach ($strays as $stray) {
            $this->addWarn(
                $stray['file'],
                $stray['line'],
                'BR-OUT-OF-RANGE',
                $stray['id'].' is outside the documented range '.self::identifier(self::FIRST_RULE).'..'.self::identifier(self::LAST_RULE)
            );
        }

        $this->renderCoverage($coverage, $missing);

        return $this->renderReport(
            'G10',
            'BUSINESS RULES - one named test per rule',
            'every rule from '.self::identifier(self::FIRST_RULE).' to '.self::identifier(self::LAST_RULE).' named by at least one test'
        );
    }

    /**
     * @param  array<string, string>  $coverage
     * @param  list<string>  $missing
     */
    private function renderCoverage(array $coverage, array $missing): void
    {
        if ($this->jsonRequested()) {
            return;
        }

        $total = self::LAST_RULE - self::FIRST_RULE + 1;

        $this->newLine();
        $this->line(sprintf(
            '  coverage      : %d / %d rule(s) named by a test',
            $total - count($missing),
            $total
        ));

        if ($coverage !== []) {
            ksort($coverage);
            $this->line('  covered       : '.implode(', ', array_keys($coverage)));
        }

        if ($missing !== []) {
            $this->line('  missing       : '.implode(', ', $missing));
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
                'name' => $match['groups'][1] ?? '',
                'line' => self::lineAt($contents, $match['offset']),
            ];
        }

        return $names;
    }

    /**
     * Business rule identifiers inside a test name, tolerating the underscore
     * form that class based method names are forced to use.
     *
     * @return list<string>
     */
    public static function identifiersIn(string $name): array
    {
        $found = [];

        foreach (self::matches('/\b'.self::RULE_PREFIX.'[-_](\d{1,3})\b/i', $name) as $match) {
            $found[] = self::identifier((int) ($match['groups'][1] ?? 0));
        }

        return array_values(array_unique($found));
    }

    public static function identifier(int $number): string
    {
        return self::RULE_PREFIX.'-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
    }
}
