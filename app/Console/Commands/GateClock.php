<?php

declare(strict_types=1);

namespace App\Console\Commands;

/**
 * Gate G4 - the single time source.
 *
 * The platform has exactly one clock: App\Services\Time\Clock. Any other way of
 * asking the machine what time it is (global helpers, Carbon statics, freshly
 * constructed date objects) is a merge blocker, because server time is the sole
 * reference for attendance windows and every other deadline.
 *
 * Detection is token based for PHP, so the scanner cannot be fooled by, and does
 * not false-positive on:
 *   - property and method access such as $session->date or $model->date(),
 *   - column names such as updated_at,
 *   - parsing helpers such as Carbon::parse() and Carbon::create(),
 *   - a constructor handed an explicit instant, new DateTimeImmutable('2026-10-12
 *     15:00:00'), which parses a fixed moment and never reads the machine clock,
 *   - the words "date" or "now" inside strings, comments and docblocks.
 *
 * A constructor whose argument cannot be read here - a variable, a call, a
 * concatenation - is reported as a warning rather than a failure: it may well be
 * a parse, but nothing in the file proves it is not the string "now".
 *
 * @see CONSTITUTION.md Article 11 and Article 13 item 2
 * @see PROJECT-CONTRACT.md section 5
 */
final class GateClock extends GateCommand
{
    protected $signature = 'gate:clock
        {--json : Emit findings as JSON instead of a table}
        {--path= : Scan a different repository root}';

    protected $description = 'G4: the only time source is App\Services\Time\Clock.';

    /** The one file allowed to touch the machine clock. */
    public const CLOCK_FILE = 'app/Services/Time/Clock.php';

    /** @var list<string> */
    private const SCAN_DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'tests'];

    /**
     * Global functions that read the machine clock.
     *
     * @var list<string>
     */
    private const GLOBAL_TIME_FUNCTIONS = [
        'now',
        'today',
        'time',
        'date',
        'mktime',
        'gmmktime',
        'microtime',
        'gmdate',
        'getdate',
        'localtime',
        'date_create',
        'date_create_immutable',
    ];

    /**
     * Classes whose static "current time" constructors are forbidden.
     *
     * @var list<string>
     */
    private const CLOCK_CLASSES = ['Carbon', 'CarbonImmutable', 'CarbonInterface', 'Date', 'DateTime', 'DateTimeImmutable'];

    /**
     * Static methods that return "the current moment".
     *
     * @var list<string>
     */
    private const CLOCK_STATIC_METHODS = ['now', 'today', 'tomorrow', 'yesterday'];

    /**
     * Constructors that capture the machine clock when called without arguments.
     *
     * @var list<string>
     */
    private const FORBIDDEN_CONSTRUCTORS = ['DateTime', 'DateTimeImmutable', 'Carbon', 'CarbonImmutable'];

    /**
     * Procedural constructors, judged by their arguments exactly like `new`.
     *
     * @var list<string>
     */
    private const CONSTRUCTOR_FUNCTIONS = ['date_create', 'date_create_immutable'];

    /**
     * Literal first arguments that mean "the current moment".
     *
     * @var list<string>
     */
    private const NOW_LITERALS = ['', 'now'];

    public function handle(): int
    {
        $files = $this->collectFiles(self::SCAN_DIRECTORIES, ['php', 'blade']);

        foreach ($files as $file) {
            if (self::isAllowedFile($file)) {
                continue;
            }

            $this->filesScanned++;
            $this->absorb($file, self::violations($file, $this->read($file)));
        }

        if (! $this->exists(self::CLOCK_FILE)) {
            $this->addWarn(
                self::CLOCK_FILE,
                0,
                'CLOCK-MISSING',
                'the single time source does not exist yet; every other file is still checked',
            );
        }

        return $this->renderReport('G4', 'CLOCK - single time source', 'zero machine-clock calls outside '.self::CLOCK_FILE);
    }

    /**
     * The Clock itself is the only exemption. Gate sources are token scanned,
     * so their pattern tables are blanked as string literals and need none.
     */
    public static function isAllowedFile(string $relative): bool
    {
        return str_replace('\\', '/', $relative) === self::CLOCK_FILE;
    }

    /**
     * Shared scanner, also consumed by gate:forbidden (Article 13 item 2).
     *
     * @return list<array{severity: string, line: int, rule: string, detail: string}>
     */
    public static function violations(string $relative, string $contents): array
    {
        $kind = self::kindOf($relative);

        if (self::isAllowedFile($relative) || $contents === '' || ! in_array($kind, ['php', 'blade'], true)) {
            return [];
        }

        return $kind === 'blade' ? self::scanBlade($contents) : self::scanPhp($contents);
    }

    /**
     * @return list<array{severity: string, line: int, rule: string, detail: string}>
     */
    private static function scanPhp(string $contents): array
    {
        $sequence = self::significantTokens($contents);
        $found = [];
        $total = count($sequence);

        for ($index = 0; $index < $total; $index++) {
            $token = $sequence[$index];
            $previous = $sequence[$index - 1] ?? null;
            $next = $sequence[$index + 1] ?? null;

            // new DateTime / new Carbon ...
            if ($token['id'] === T_NEW) {
                if ($next !== null && self::isNameToken($next['id'])) {
                    $class = self::lastSegment($next['text']);

                    if (in_array($class, self::FORBIDDEN_CONSTRUCTORS, true)) {
                        $verdict = self::judgeConstructor($sequence, $index + 1);

                        if ($verdict !== null) {
                            $found[] = self::hit(
                                $verdict['severity'],
                                $token['line'],
                                'CLOCK-NEW',
                                'new '.$class.'() '.$verdict['reason'].'; the only time source is Clock::now()',
                            );
                        }
                    }
                }

                continue;
            }

            // Carbon::now(), Date::today(), Carbon::setTestNow() ...
            if ($token['id'] === T_DOUBLE_COLON) {
                $class = $previous !== null ? self::lastSegment($previous['text']) : '';
                $method = $next !== null ? $next['text'] : '';

                if ($method === 'setTestNow') {
                    $found[] = self::hit(
                        self::FAIL,
                        $token['line'],
                        'CLOCK-TEST-NOW',
                        $class.'::setTestNow() is forbidden even in tests; use Clock::fake()',
                    );

                    continue;
                }

                if (in_array($class, self::CLOCK_CLASSES, true) && in_array($method, self::CLOCK_STATIC_METHODS, true)) {
                    $found[] = self::hit(
                        self::FAIL,
                        $token['line'],
                        'CLOCK-STATIC',
                        $class.'::'.$method.'() is forbidden; use Clock::now() / Clock::riyadh()',
                    );
                }

                continue;
            }

            if (! self::isNameToken($token['id'])) {
                continue;
            }

            $name = strtolower(self::lastSegment($token['text']));

            if (! in_array($name, self::GLOBAL_TIME_FUNCTIONS, true)) {
                continue;
            }

            // Only a call is a violation: bare identifiers are constants, keys or names.
            if ($next === null || $next['text'] !== '(') {
                continue;
            }

            if ($previous !== null) {
                $previousId = $previous['id'];

                // ->date(), ?->date(), Foo::date(), function date(), new date(), const date
                if (in_array($previousId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true)) {
                    continue;
                }

                // Variable functions such as $date(...) are not the global helper.
                if ($previous['text'] === '$' || $previousId === T_VARIABLE) {
                    continue;
                }
            }

            // date_create('2026-10-12') parses a fixed instant, exactly like the
            // constructor it wraps, so it is judged by its argument.
            if (in_array($name, self::CONSTRUCTOR_FUNCTIONS, true)) {
                $verdict = self::judgeConstructor($sequence, $index);

                if ($verdict !== null) {
                    $found[] = self::hit(
                        $verdict['severity'],
                        $token['line'],
                        'CLOCK-NEW',
                        $name.'() '.$verdict['reason'].'; the only time source is Clock::now()',
                    );
                }

                continue;
            }

            $found[] = self::hit(
                self::FAIL,
                $token['line'],
                'CLOCK-GLOBAL',
                $name.'() reads the machine clock; use Clock::now()',
            );
        }

        return $found;
    }

    /**
     * Judge a constructor call by its first argument.
     *
     * No argument, an empty string, the literal "now" and null all capture the
     * machine clock and fail. Any other readable literal is a parse and passes.
     * An argument this scanner cannot read warns, because it cannot be proven
     * innocent and the constitution fails safe (Article 7).
     *
     * @param  list<array{id: int, text: string, line: int}>  $sequence
     * @param  int  $nameIndex  index of the class or function name token
     * @return array{severity: string, reason: string}|null null when allowed
     */
    private static function judgeConstructor(array $sequence, int $nameIndex): ?array
    {
        $open = $sequence[$nameIndex + 1] ?? null;

        if ($open === null || $open['text'] !== '(') {
            return ['severity' => self::FAIL, 'reason' => 'takes no argument, so it captures the machine clock'];
        }

        $first = $sequence[$nameIndex + 2] ?? null;

        if ($first === null || $first['text'] === ')') {
            return ['severity' => self::FAIL, 'reason' => 'takes no argument, so it captures the machine clock'];
        }

        $after = $sequence[$nameIndex + 3] ?? null;
        $isSingleToken = $after !== null && ($after['text'] === ')' || $after['text'] === ',');

        if (! $isSingleToken) {
            return ['severity' => self::WARN, 'reason' => 'is handed an expression this gate cannot read; prove it is a fixed instant, not "now"'];
        }

        if ($first['id'] === T_CONSTANT_ENCAPSED_STRING) {
            $literal = strtolower(trim(substr($first['text'], 1, -1)));

            return in_array($literal, self::NOW_LITERALS, true)
                ? ['severity' => self::FAIL, 'reason' => 'is handed "now", so it captures the machine clock']
                : null;
        }

        if (strtolower($first['text']) === 'null') {
            return ['severity' => self::FAIL, 'reason' => 'is handed null, so it captures the machine clock'];
        }

        return ['severity' => self::WARN, 'reason' => 'is handed a value this gate cannot read; prove it is a fixed instant, not "now"'];
    }

    /**
     * Blade has no PHP tokenizer, so comments and string bodies are blanked
     * first and the remaining code is matched with anchored patterns.
     *
     * @return list<array{severity: string, line: int, rule: string, detail: string}>
     */
    private static function scanBlade(string $contents): array
    {
        // Comments and string bodies are blanked: "date" inside a translation
        // key or a sentence is not a call.
        $code = self::sanitizeSource($contents, 'blade');

        // Comments blanked, strings kept: the constructor rule has to read its
        // own argument to tell new DateTimeImmutable('2026-10-12') from new
        // DateTimeImmutable().
        $withStrings = self::sanitizeSource($contents, 'blade', false);

        $found = [];

        $patterns = [
            'CLOCK-GLOBAL' => '/(?<![\w$>:.\-])\\\\?\b(now|today|time|date|mktime|microtime|gmdate)\s*\(/i',
            'CLOCK-STATIC' => '/\b(Carbon|CarbonImmutable|Date)::(now|today|tomorrow|yesterday)\s*\(/',
            'CLOCK-TEST-NOW' => '/\bsetTestNow\s*\(/',
        ];

        foreach ($patterns as $rule => $pattern) {
            foreach (self::matches($pattern, $code) as $match) {
                $found[] = self::hit(
                    self::FAIL,
                    self::lineAt($code, $match['offset']),
                    $rule,
                    self::excerpt($match['text']).' - the only time source is Clock',
                );
            }
        }

        // new DateTime / new Carbon with no argument, with "now" or with null.
        $constructor = '/\bnew\s+\\\\?(?:DateTime|DateTimeImmutable|Carbon|CarbonImmutable)\b'
            .'(?:\s*\(\s*(?:\)|([\'"])now\1\s*\)|null\s*\))|\s*(?!\s*\())/';

        foreach (self::matches($constructor, $withStrings) as $match) {
            $found[] = self::hit(
                self::FAIL,
                self::lineAt($withStrings, $match['offset']),
                'CLOCK-NEW',
                self::excerpt($match['text']).' captures the machine clock - the only time source is Clock',
            );
        }

        return $found;
    }

    /**
     * Tokens without whitespace or comments, each carrying its line number.
     *
     * @return list<array{id: int, text: string, line: int}>
     */
    private static function significantTokens(string $contents): array
    {
        $tokens = @token_get_all($contents);
        $sequence = [];
        $line = 1;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $id = (int) $token[0];
                $text = (string) $token[1];
                $line = (int) $token[2];
            } else {
                $id = 0;
                $text = (string) $token;
            }

            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $line += substr_count($text, "\n");

                continue;
            }

            $sequence[] = ['id' => $id, 'text' => $text, 'line' => $line];
            $line += substr_count($text, "\n");
        }

        return $sequence;
    }

    private static function isNameToken(int $id): bool
    {
        return $id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED;
    }

    /**
     * Last segment of a possibly namespaced name: \Carbon\Carbon -> Carbon.
     */
    private static function lastSegment(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }
}
