<?php

declare(strict_types=1);

namespace App\Console\Commands;

/**
 * Gate G6 - design values come from tokens, and never from the yellow family.
 *
 * Two independent scans:
 *
 *   A. Literal design values. Hex colours, rgb()/rgba()/hsl()/hsla() and raw px
 *      lengths in Blade or in CSS outside resources/css/tokens.css, plus
 *      font-family / font-size declared with anything other than var().
 *
 *   B. The yellow and gold prohibition, made mechanical. Every hex, rgb() and
 *      hsl() colour anywhere in the repository's CSS, Blade, SVG and JS is
 *      converted to HSL and rejected when hue is 40deg..65deg with saturation
 *      above 25% and lightness above 25%. Colour names in the yellow family are
 *      rejected by name. tokens.css is exempt from scan A but never from this
 *      one: the ban is absolute, including inside the token definitions.
 *
 * The two brand colours and the single warning colour are safe by construction:
 * violet hue 267, teal hue 181, burnt orange hue 33.
 *
 * @see CONSTITUTION.md Article 13 items 4 and 5, Article 14
 * @see PROJECT-CONTRACT.md section 1
 */
final class GateTokens extends GateCommand
{
    protected $signature = 'gate:tokens
        {--json : Emit findings as JSON instead of a table}
        {--path= : Scan a different repository root}';

    protected $description = 'G6: no literal design values, and no yellow or gold in any shade.';

    /** The single source of design values (Article 6). */
    public const TOKENS_FILE = 'resources/css/tokens.css';

    /** @var list<string> */
    private const LITERAL_SCAN_DIRECTORIES = ['app', 'config', 'database', 'public', 'resources', 'routes'];

    /** @var list<string> */
    private const COLOUR_SCAN_DIRECTORIES = ['app', 'brand', 'config', 'database', 'public', 'resources', 'routes'];

    /** Root level build configuration that may also carry colour literals. */
    private const EXTRA_FILES = ['tailwind.config.js', 'tailwind.config.mjs', 'tailwind.config.cjs', 'postcss.config.js', 'vite.config.js'];

    /** Forbidden hue window, in degrees (Article 14). */
    public const YELLOW_HUE_MIN = 40.0;

    public const YELLOW_HUE_MAX = 65.0;

    public const YELLOW_MIN_SATURATION = 25.0;

    public const YELLOW_MIN_LIGHTNESS = 25.0;

    /**
     * Colour names in the yellow and gold family, matched case-insensitively.
     *
     * @var list<string>
     */
    private const FORBIDDEN_COLOUR_NAMES = [
        'gold',
        'goldenrod',
        'darkgoldenrod',
        'palegoldenrod',
        'yellow',
        'yellowgreen',
        'lightyellow',
        'lemonchiffon',
        'khaki',
        'darkkhaki',
        'amber',
        'moccasin',
        'papayawhip',
        'cornsilk',
        'mustard',
        'saffron',
    ];

    /** Hex literal, ignoring HTML numeric entities such as &#123;. */
    public const HEX_PATTERN = '/(?<![&\w])#([0-9a-fA-F]{3,8})(?![0-9a-fA-F\w])/';

    public const FUNCTIONAL_COLOUR_PATTERN = '/\b(rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\s*\(/i';

    public const PIXEL_PATTERN = '/(?<![\w.])(-?\d+(?:\.\d+)?)px\b/i';

    public const FONT_PATTERN = '/\b(font-family|font-size)\s*:\s*([^;{}"\']+)/i';

    /** Lengths tolerated as hairlines and zeroes; still reported as warnings. */
    private const TOLERATED_PIXELS = ['0', '-0', '0.5', '-0.5', '1', '-1'];

    public function handle(): int
    {
        $literalFiles = $this->fileSet(self::LITERAL_SCAN_DIRECTORIES, ['blade', 'css', 'js']);
        $colourFiles = $this->fileSet(self::COLOUR_SCAN_DIRECTORIES, ['blade', 'css', 'js', 'svg']);

        foreach (self::EXTRA_FILES as $extra) {
            if ($this->exists($extra)) {
                $literalFiles[] = $extra;
                $colourFiles[] = $extra;
            }
        }

        $scanned = array_unique(array_merge($literalFiles, $colourFiles));
        $this->filesScanned = count($scanned);

        foreach (array_unique($literalFiles) as $file) {
            $this->absorb($file, self::literalViolations($file, $this->read($file)));
        }

        foreach (array_unique($colourFiles) as $file) {
            $this->absorb($file, self::yellowViolations($file, $this->read($file)));
        }

        if (! $this->exists(self::TOKENS_FILE)) {
            $this->addWarn(self::TOKENS_FILE, 0, 'TOKENS-MISSING', 'the design token file does not exist yet');
        }

        return $this->renderReport('G6', 'TOKENS - design values and the yellow ban', 'zero literal design values outside tokens.css; zero yellow or gold anywhere');
    }

    /**
     * @param  list<string>  $directories
     * @param  list<string>  $kinds
     * @return list<string>
     */
    private function fileSet(array $directories, array $kinds): array
    {
        return array_values(array_filter(
            $this->collectFiles($directories, $kinds),
            static fn (string $file): bool => ! self::isGateSource($file)
        ));
    }

    // ---------------------------------------------------- scan A: literals

    /**
     * Literal design values outside the token file.
     *
     * Shared scanner, also consumed by gate:forbidden (Article 13 item 4).
     *
     * @return list<array{severity: string, line: int, rule: string, detail: string}>
     */
    public static function literalViolations(string $relative, string $contents): array
    {
        $kind = self::kindOf($relative);

        if ($contents === '' || ! in_array($kind, ['blade', 'css', 'js'], true)) {
            return [];
        }

        if (str_replace('\\', '/', $relative) === self::TOKENS_FILE) {
            return [];
        }

        $code = self::sanitizeSource($contents, $kind, false);
        $found = [];

        // Article 13 item 4 names Blade and CSS, so those block the merge. A
        // colour built in JavaScript still contradicts Article 6, but the
        // article does not name it, so it warns instead of blocking - and it
        // warns identically in gate:forbidden, which shares this scanner.
        $severity = $kind === 'js' ? self::WARN : self::FAIL;

        foreach (self::matches(self::HEX_PATTERN, $code) as $match) {
            if (! self::isColourHex($match['groups'][1] ?? '') || self::isReference($code, $match['offset'])) {
                continue;
            }

            $found[] = self::hit(
                $severity,
                self::lineAt($code, $match['offset']),
                'TOKENS-HEX',
                'literal colour '.$match['text'].'; use var(--token) from '.self::TOKENS_FILE
            );
        }

        foreach (self::matches(self::FUNCTIONAL_COLOUR_PATTERN, $code) as $match) {
            $found[] = self::hit(
                $severity,
                self::lineAt($code, $match['offset']),
                'TOKENS-COLOUR-FN',
                'literal colour function '.trim($match['text']).'; use var(--token)'
            );
        }

        if ($kind !== 'js') {
            foreach (self::matches(self::PIXEL_PATTERN, $code) as $match) {
                $line = self::lineAt($code, $match['offset']);

                if (self::isBreakpointLine($code, $line)) {
                    continue;
                }

                $value = $match['groups'][1] ?? '';
                $tolerated = in_array($value, self::TOLERATED_PIXELS, true);

                $found[] = self::hit(
                    $tolerated ? self::WARN : self::FAIL,
                    $line,
                    'TOKENS-PX',
                    'raw length '.$match['text'].($tolerated
                        ? ' (hairline or zero) should still come from a token'
                        : '; spacing and sizing come from tokens')
                );
            }

            foreach (self::matches(self::FONT_PATTERN, $code) as $match) {
                $value = trim($match['groups'][2] ?? '');

                if ($value === '' || str_starts_with($value, 'var(') || str_starts_with($value, 'inherit')) {
                    continue;
                }

                $found[] = self::hit(
                    self::FAIL,
                    self::lineAt($code, $match['offset']),
                    'TOKENS-FONT',
                    'literal '.strtolower($match['groups'][1] ?? 'font').' value; use var(--token)'
                );
            }
        }

        return $found;
    }

    /**
     * Anchors, SVG references and url(#id) are not colours.
     */
    private static function isReference(string $code, int $offset): bool
    {
        $window = strtolower(substr($code, max(0, $offset - 14), min(14, $offset)));

        foreach (['href="', "href='", 'href=', 'url(', 'aria-controls="', 'aria-controls='] as $needle) {
            if (str_ends_with($window, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Media and container queries cannot use custom properties, so their
     * breakpoints are the one place a raw px length is unavoidable.
     */
    private static function isBreakpointLine(string $code, int $line): bool
    {
        $lines = preg_split('/\r\n|\r|\n/', $code);

        if ($lines === false || ! isset($lines[$line - 1])) {
            return false;
        }

        $text = strtolower($lines[$line - 1]);

        return str_contains($text, '@media') || str_contains($text, '@container') || str_contains($text, '@supports');
    }

    // ------------------------------------------------- scan B: yellow ban

    /**
     * The Article 14 prohibition, made mechanical.
     *
     * Shared scanner, also consumed by gate:forbidden (Article 13 item 5).
     *
     * @return list<array{severity: string, line: int, rule: string, detail: string}>
     */
    public static function yellowViolations(string $relative, string $contents): array
    {
        $kind = self::kindOf($relative);

        if ($contents === '' || ! in_array($kind, ['blade', 'css', 'js', 'svg'], true)) {
            return [];
        }

        $code = self::sanitizeSource($contents, $kind === 'svg' ? 'css' : $kind, false);
        $found = [];

        foreach (self::matches(self::HEX_PATTERN, $code) as $match) {
            $hex = $match['groups'][1] ?? '';

            if (! self::isColourHex($hex) || self::isReference($code, $match['offset'])) {
                continue;
            }

            $rgb = self::hexToRgb($hex);

            if ($rgb === null) {
                continue;
            }

            $found = self::judge($found, $code, $match['offset'], $match['text'], self::rgbToHsl($rgb[0], $rgb[1], $rgb[2]));
        }

        foreach (self::matches('/\b(rgba?|hsla?)\s*\(/i', $code) as $match) {
            $open = $match['offset'] + strlen($match['text']) - 1;
            $arguments = self::callArguments($code, $open);
            $function = strtolower(rtrim(trim($match['text']), '('));
            $hsl = str_starts_with($function, 'rgb')
                ? self::parseRgbArguments($arguments)
                : self::parseHslArguments($arguments);

            if ($hsl === null) {
                continue;
            }

            $found = self::judge($found, $code, $match['offset'], $function.'('.self::excerpt($arguments, 24).')', $hsl);
        }

        foreach (self::matches('/\b('.implode('|', self::FORBIDDEN_COLOUR_NAMES).')\b/i', $code) as $match) {
            $found[] = self::hit(
                self::FAIL,
                self::lineAt($code, $match['offset']),
                'YELLOW-NAME',
                'colour name "'.strtolower($match['text']).'" belongs to the forbidden yellow and gold family'
            );
        }

        foreach (self::matches('/\b(oklch|oklab|lab|lch|hwb|color)\s*\(/i', $code) as $match) {
            $found[] = self::hit(
                self::WARN,
                self::lineAt($code, $match['offset']),
                'YELLOW-UNVERIFIABLE',
                trim($match['text']).' cannot be converted here; prove by hand that it is not in the yellow family'
            );
        }

        return $found;
    }

    /**
     * @param  list<array{severity: string, line: int, rule: string, detail: string}>  $found
     * @param  array{0: float, 1: float, 2: float}  $hsl
     * @return list<array{severity: string, line: int, rule: string, detail: string}>
     */
    private static function judge(array $found, string $code, int $offset, string $literal, array $hsl): array
    {
        if (! self::isForbiddenYellow($hsl[0], $hsl[1], $hsl[2])) {
            return $found;
        }

        $found[] = self::hit(
            self::FAIL,
            self::lineAt($code, $offset),
            'YELLOW-HUE',
            sprintf(
                '%s is hue %.0fdeg s%.0f%% l%.0f%%: yellow or gold, forbidden in every shade',
                $literal,
                $hsl[0],
                $hsl[1],
                $hsl[2]
            )
        );

        return $found;
    }

    public static function isForbiddenYellow(float $hue, float $saturation, float $lightness): bool
    {
        return $hue >= self::YELLOW_HUE_MIN
            && $hue <= self::YELLOW_HUE_MAX
            && $saturation > self::YELLOW_MIN_SATURATION
            && $lightness > self::YELLOW_MIN_LIGHTNESS;
    }

    private static function isColourHex(string $digits): bool
    {
        return in_array(strlen($digits), [3, 4, 6, 8], true);
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function hexToRgb(string $digits): ?array
    {
        $length = strlen($digits);

        if ($length === 3 || $length === 4) {
            $digits = $digits[0].$digits[0].$digits[1].$digits[1].$digits[2].$digits[2];
        } elseif ($length === 6 || $length === 8) {
            $digits = substr($digits, 0, 6);
        } else {
            return null;
        }

        return [
            (int) hexdec(substr($digits, 0, 2)),
            (int) hexdec(substr($digits, 2, 2)),
            (int) hexdec(substr($digits, 4, 2)),
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float} hue 0..360, saturation and lightness 0..100
     */
    public static function rgbToHsl(int $red, int $green, int $blue): array
    {
        $r = max(0, min(255, $red)) / 255;
        $g = max(0, min(255, $green)) / 255;
        $b = max(0, min(255, $blue)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;
        $lightness = ($max + $min) / 2;

        if ($delta <= 0.0) {
            return [0.0, 0.0, $lightness * 100];
        }

        $saturation = $lightness > 0.5
            ? $delta / (2 - $max - $min)
            : $delta / ($max + $min);

        if ($max === $r) {
            $hue = 60 * fmod(($g - $b) / $delta, 6);
        } elseif ($max === $g) {
            $hue = 60 * ((($b - $r) / $delta) + 2);
        } else {
            $hue = 60 * ((($r - $g) / $delta) + 4);
        }

        if ($hue < 0) {
            $hue += 360;
        }

        return [$hue, $saturation * 100, $lightness * 100];
    }

    /**
     * Text between the parentheses of a call whose '(' sits at $offset.
     */
    private static function callArguments(string $code, int $offset): string
    {
        $length = strlen($code);
        $depth = 0;

        for ($index = $offset; $index < $length; $index++) {
            $character = $code[$index];

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;

                if ($depth <= 0) {
                    return substr($code, $offset + 1, $index - $offset - 1);
                }
            }
        }

        return substr($code, $offset + 1, min(64, $length - $offset - 1));
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null
     */
    private static function parseRgbArguments(string $arguments): ?array
    {
        $parts = self::numericParts($arguments);

        if (count($parts) < 3) {
            return null;
        }

        $channels = [];

        foreach (array_slice($parts, 0, 3) as $part) {
            $channels[] = str_contains($part, '%')
                ? (int) round(((float) rtrim(trim($part), '%')) * 2.55)
                : (int) round((float) $part);
        }

        return self::rgbToHsl($channels[0], $channels[1], $channels[2]);
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null
     */
    private static function parseHslArguments(string $arguments): ?array
    {
        $parts = self::numericParts($arguments);

        if (count($parts) < 3) {
            return null;
        }

        $raw = trim($parts[0]);
        $hue = (float) $raw;

        if (str_contains($raw, 'turn')) {
            $hue *= 360;
        } elseif (str_contains($raw, 'rad')) {
            $hue *= 180 / M_PI;
        }

        $hue = fmod($hue, 360);

        if ($hue < 0) {
            $hue += 360;
        }

        return [
            $hue,
            (float) rtrim(trim($parts[1]), '%'),
            (float) rtrim(trim($parts[2]), '%'),
        ];
    }

    /**
     * Split colour function arguments on commas or whitespace, dropping any
     * alpha component introduced by a slash.
     *
     * @return list<string>
     */
    private static function numericParts(string $arguments): array
    {
        $arguments = (string) preg_replace('#/.*$#', '', $arguments);
        $pieces = preg_split('/[,\s]+/', trim($arguments));

        if ($pieces === false) {
            return [];
        }

        $parts = [];

        foreach ($pieces as $piece) {
            if ($piece === '') {
                continue;
            }

            // A var() or calc() component makes the colour unresolvable here.
            if (preg_match('/^-?\d/', $piece) !== 1) {
                return [];
            }

            $parts[] = $piece;
        }

        return $parts;
    }
}
