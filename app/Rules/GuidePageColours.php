<?php

declare(strict_types=1);

namespace App\Rules;

use App\Console\Commands\GateTokens;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * No yellow and no gold in a guide page, in any shade (D-127, CONSTITUTION
 * Article 14).
 *
 * The page is a whole HTML document the general supervisor uploads, so the G6
 * gate never sees it. This rule runs the gate's own scanner
 * (GateTokens::yellowViolations — the same hue window, the same colour names)
 * over every place a page can set a colour: its <style> blocks, its style=""
 * attributes and the SVG/HTML colour attributes. Words in the text are not
 * colours; a guide may say "gold" in a sentence.
 *
 * @see D-127 · CONSTITUTION Art. 13 (item 5), Art. 14
 */
final class GuidePageColours implements ValidationRule
{
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $offenders = self::offenders($value);

        if ($offenders !== []) {
            $fail((string) __('admin.final_project.guide.errors.yellow', ['colours' => implode((string) __('admin.final_project.guide.errors.list_separator'), $offenders)]));
        }
    }

    /**
     * The forbidden colours found in the page's styling, as written.
     *
     * @return list<string>
     */
    public static function offenders(string $html): array
    {
        $styling = implode("\n", self::stylingOf($html));

        if ($styling === '') {
            return [];
        }

        $found = [];

        foreach (GateTokens::yellowViolations('guide-page.css', $styling) as $violation) {
            if ($violation['severity'] !== GateTokens::FAIL) {
                continue;
            }

            // "#fff8e8 is hue 43deg …" or 'colour name "gold" belongs …'.
            $found[] = match (true) {
                preg_match('/^(.+?) is hue /', $violation['detail'], $literal) === 1 => $literal[1],
                preg_match('/"([^"]+)"/', $violation['detail'], $name) === 1 => $name[1],
                default => $violation['detail'],
            };
        }

        return array_values(array_unique($found));
    }

    /**
     * Every piece of the page that can carry a colour.
     *
     * @return list<string>
     */
    private static function stylingOf(string $html): array
    {
        $parts = [];

        if (preg_match_all('#<style\b[^>]*>(.*?)</style\s*>#is', $html, $blocks) > 0) {
            array_push($parts, ...$blocks[1]);
        }

        $attributes = '(?:style|fill|stroke|color|bgcolor|stop-color|flood-color|lighting-color)';

        if (preg_match_all('#\s'.$attributes.'\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $html, $values, PREG_SET_ORDER) > 0) {
            foreach ($values as $value) {
                // Group 2 holds a double-quoted value, group 3 a single-quoted one.
                $parts[] = 'x{'.($value[3] ?? $value[2] ?? '').'}';
            }
        }

        return $parts;
    }
}
