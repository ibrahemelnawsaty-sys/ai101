<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * The design tokens an e-mail template needs, read out of `tokens.css`.
 *
 * WHY THIS EXISTS RATHER THAN LITERALS IN THE TEMPLATE
 * E-mail clients strip `<style>`, ignore custom properties and mangle external
 * sheets, so an e-mail must carry colour, spacing and type inline. That collides
 * with Article 6 — a design literal belongs in `tokens.css` and nowhere else —
 * and `gate:tokens` scans every Blade file for exactly that, hex and `px` alike.
 *
 * The easy way out would have been to exempt `resources/views/mail/` from the
 * gate. That is backwards: widening a gate so one's own code can pass is how a
 * gate stops meaning anything. So the template asks by ROLE and this class reads
 * the real value from the token file at render time. One source, and a letter
 * whose look cannot drift from the interface even after the brand is re-tuned.
 *
 * @see CONSTITUTION.md Article 6, Article 14 · PRD §5.5, §5.6 · D-49
 */
final class EmailPalette
{
    private const CACHE_KEY = 'athar.mail.theme';

    private const CACHE_SECONDS = 86400;

    /** Colour roles a letter may ask for. */
    private const COLOURS = [
        'brand' => 'violet-500',
        'brandDeep' => 'violet-900',
        'brandDark' => 'violet-700',
        'brandLight' => 'violet-300',
        'brandTint' => 'violet-050',
        'accent' => 'teal-500',
        'accentDark' => 'teal-700',
        'accentLight' => 'teal-300',
        'accentTint' => 'teal-100',
        'ink' => 'ink',
        'inkSoft' => 'ink-3',
        'inkText' => 'n-900',
        'body' => 'n-600',
        'muted' => 'n-500',
        'line' => 'n-200',
        'surface' => 'n-000',
        'surfaceAlt' => 'n-100',
        'white' => 'white',
        'black' => 'black',
    ];

    /** Lengths, kept to the few a letter actually uses. */
    private const LENGTHS = [
        'gapXs' => 's1',
        'gapSm' => 's2',
        'gapMd' => 's3',
        'gapLg' => 's4',
        'gapXl' => 's6',
        'padCard' => 's7',
        'radius' => 'r-md',
        'radiusCard' => 'r-lg',
        'hairline' => 'bw-hairline',
        'textSm' => 'fs-sm',
        'textXs' => 'fs-xs',
        'cardWidth' => 'd-9',
        'gapHuge' => 's12',
        'radiusPill' => 's11',
        // A spacer row carries an &nbsp; so Outlook will not collapse it, then
        // zeroes the type so the cell is exactly its stated height and not one
        // line of text tall. That zero is structural, not typographic — but it
        // is still a value, so it comes from the token file like every other.
        'zero' => 's0',
    ];

    /**
     * Every role resolved: colours as `#rrggbb`, lengths as CSS lengths, plus
     * the font stack.
     *
     * Cached for a day — the token file changes on deploy, not at runtime, and
     * a queued send must not re-read a file per message.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        /** @var array<string, string> $theme */
        $theme = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->read());

        return $theme;
    }

    /** @return array<string, string> */
    private function read(): array
    {
        $tokens = $this->tokens();
        $theme = [];

        // A missing token is a deployment fault, and inventing a stand-in here
        // would hide it behind a letter that merely looks slightly wrong. It
        // fails loudly instead (art. 7) — and writing a fallback hex in this
        // file would itself have been the colour literal the class exists to
        // avoid.
        foreach ([...self::COLOURS, ...self::LENGTHS] as $role => $token) {
            if (! isset($tokens[$token])) {
                throw new \RuntimeException(sprintf(
                    'tokens.css does not define --%s, needed by the e-mail role "%s".',
                    $token,
                    $role,
                ));
            }

            $theme[$role] = $tokens[$token];
        }

        if (! isset($tokens['font'])) {
            throw new \RuntimeException('tokens.css does not define --font.');
        }

        $theme['font'] = $tokens['font'];

        return $theme;
    }

    /**
     * Every `--name: value` in the token file, first definition winning: the
     * root block declares the primitives and the shell blocks below only
     * re-point semantic roles.
     *
     * @return array<string, string>
     */
    private function tokens(): array
    {
        $path = base_path('resources/css/tokens.css');

        $source = File::exists($path) ? (string) File::get($path) : '';

        preg_match_all('/--([a-z0-9-]+):\s*([^;\n]+);/i', $source, $matches, PREG_SET_ORDER);

        $tokens = [];

        foreach ($matches as $match) {
            $value = trim($match[2]);

            // Only concrete values are useful here: a role pointing at another
            // role (`var(--x)`) would have to be resolved, and an e-mail has no
            // cascade to resolve it in.
            if (str_contains($value, 'var(')) {
                continue;
            }

            $tokens[$match[1]] ??= $value;
        }

        return $tokens;
    }
}
