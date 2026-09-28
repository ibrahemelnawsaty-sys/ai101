<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\GuidePageDom;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * What the guide page's Content-Security-Policy cannot stop, refused when the
 * page is saved (D-127, security review).
 *
 * The page is served with scripts, requests, forms, frames and <base> all
 * shut off (GuideDocument). CSP has no directive for a document leaving on its
 * own, so a pasted <meta http-equiv="refresh"> could turn the platform's own
 * guide link — the one in the trainee's e-mail — into a jump to a look-alike
 * sign-in page. Refused here, with the other markup that reaches outside the
 * page without a click:
 *
 *   <meta http-equiv>   anything but the harmless Content-Type /
 *                       Content-Security-Policy / X-UA-Compatible
 *   <meta name=referrer> which would loosen the platform's Referrer-Policy
 *   <base>              which would re-point every relative link
 *   <link rel=…>        dns-prefetch, preconnect, prefetch, prerender,
 *                       preload, modulepreload — requests nobody clicked
 *
 * @see D-127 · CONSTITUTION Art. 7, Art. 24
 */
final class GuidePageSafety implements ValidationRule
{
    private const HARMLESS_HTTP_EQUIV = ['content-type', 'content-security-policy', 'x-ua-compatible'];

    private const REACHING_LINKS = ['dns-prefetch', 'preconnect', 'prefetch', 'prerender', 'preload', 'modulepreload'];

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
     * The offending markup, named as the supervisor would find it in the page
     * — or null when the page cannot be read at all.
     *
     * @return list<string>|null
     */
    public static function offenders(string $html): ?array
    {
        $xpath = GuidePageDom::load($html);

        if ($xpath === null) {
            return null;
        }

        $found = [];

        foreach (GuidePageDom::values($xpath, '//meta/@http-equiv') as $equiv) {
            if (! in_array(strtolower(trim($equiv)), self::HARMLESS_HTTP_EQUIV, true)) {
                $found[] = '<meta http-equiv="'.$equiv.'">';
            }
        }

        foreach (GuidePageDom::values($xpath, '//meta/@name') as $name) {
            if (strtolower(trim($name)) === 'referrer') {
                $found[] = '<meta name="referrer">';
            }
        }

        if (GuidePageDom::values($xpath, '//base') !== []) {
            $found[] = '<base>';
        }

        foreach (GuidePageDom::values($xpath, '//link/@rel') as $rel) {
            foreach (preg_split('/\s+/', strtolower(trim($rel))) ?: [] as $token) {
                if (in_array($token, self::REACHING_LINKS, true)) {
                    $found[] = '<link rel="'.$token.'">';
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The refusal, naming what was found.
     *
     * @param  list<string>  $offenders
     */
    public static function message(array $offenders): string
    {
        return (string) __('admin.final_project.guide.errors.unsafe', [
            'elements' => implode((string) __('admin.final_project.guide.errors.list_separator'), $offenders),
        ]);
    }
}
