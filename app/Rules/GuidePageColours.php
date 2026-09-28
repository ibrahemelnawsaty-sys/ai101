<?php

declare(strict_types=1);

namespace App\Rules;

use App\Console\Commands\GateTokens;
use App\Support\GuidePageDom;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * No yellow and no gold in a guide page, in any shade (D-127, CONSTITUTION
 * Article 14).
 *
 * The page is a whole HTML document the general supervisor uploads, so the G6
 * gate never sees it. This rule runs the gate's own scanner
 * (GateTokens::yellowViolations — the same hue window, the same colour names)
 * over every place the parsed page sets a colour: its <style> blocks, its
 * style="" attributes and the SVG/HTML colour attributes, entities and CSS
 * escapes decoded first. Words in the text are not colours; a guide may say
 * "gold" in a sentence.
 *
 * It fails CLOSED (Article 7): a colour the gate cannot convert (oklch(),
 * lab(), color() …) is refused rather than trusted, and a page that cannot be
 * parsed is refused whole.
 *
 * @see D-127 · CONSTITUTION Art. 7, Art. 13 (item 5), Art. 14
 */
final class GuidePageColours implements ValidationRule
{
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $offenders = self::offenders($value);

        if ($offenders === null) {
            $fail((string) __('admin.final_project.guide.errors.unreadable'));

            return;
        }

        if ($offenders !== []) {
            $fail(self::message($offenders));
        }
    }

    /**
     * The forbidden or unverifiable colours in the page's styling, as
     * written — or null when the page cannot be read at all.
     *
     * @return list<string>|null
     */
    public static function offenders(string $html): ?array
    {
        $xpath = GuidePageDom::load($html);

        if ($xpath === null) {
            return null;
        }

        $styling = implode("\n", self::stylingOf($xpath));

        if ($styling === '') {
            return [];
        }

        $found = [];

        foreach (GateTokens::yellowViolations('guide-page.css', $styling) as $violation) {
            // "#fff8e8 is hue 43deg …", 'colour name "gold" belongs …', or
            // "oklch( cannot be converted here …".
            $found[] = match (true) {
                preg_match('/^(.+?) is hue /', $violation['detail'], $literal) === 1 => $literal[1],
                preg_match('/"([^"]+)"/', $violation['detail'], $name) === 1 => $name[1],
                preg_match('/^(\S+)/', $violation['detail'], $function) === 1 => $function[1],
                default => $violation['detail'],
            };
        }

        return array_values(array_unique($found));
    }

    /**
     * Every piece of the page that can carry a colour, decoded.
     *
     * @return list<string>
     */
    private static function stylingOf(\DOMXPath $xpath): array
    {
        $parts = GuidePageDom::values($xpath, '//style');

        $colourAttributes = GuidePageDom::values(
            $xpath,
            '//@*[local-name()="style" or local-name()="fill" or local-name()="stroke" or local-name()="color"'
            .' or local-name()="bgcolor" or local-name()="stop-color" or local-name()="flood-color" or local-name()="lighting-color"]',
        );

        foreach ($colourAttributes as $value) {
            $parts[] = 'x{'.$value.'}';
        }

        return array_map(self::decodeCssEscapes(...), $parts);
    }

    /** `gol\64` is "gold" to a browser; it is "gold" to this rule too. */
    private static function decodeCssEscapes(string $css): string
    {
        $decoded = preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6})\s?/',
            static fn (array $match): string => mb_chr((int) hexdec($match[1]), 'UTF-8') ?: '',
            $css,
        );

        return preg_replace('/\\\\(.)/su', '$1', $decoded ?? $css) ?? $css;
    }

    /**
     * The refusal, naming what was found.
     *
     * @param  list<string>  $offenders
     */
    public static function message(array $offenders): string
    {
        return (string) __('admin.final_project.guide.errors.yellow', [
            'colours' => implode((string) __('admin.final_project.guide.errors.list_separator'), $offenders),
        ]);
    }
}
