<?php

declare(strict_types=1);

namespace App\Services\FinalProject;

use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Serves a guide page (D-127): the document the general supervisor uploaded,
 * shown as it is — and unable to act.
 *
 * WHY A POLICY OF ITS OWN
 * The page opens inside a signed-in trainee's session. A script in it — pasted
 * by mistake, or by an account that was taken over — would act as that
 * trainee. So the response carries its own Content-Security-Policy (the global
 * SecurityHeaders middleware leaves an existing one alone):
 *
 *   script-src   a nonce minted here, carried by ONE script: the platform's
 *                own copy-button script (resources/js/guide-page.js). Every
 *                <script> and on* handler inside the page is dead.
 *   connect-src  'none' — nothing on the page can send a request.
 *   form-action  'none' — nor submit a form.
 *   base-uri     'none' — nor re-point its relative links.
 *   frame-*      'none' — nor frame, nor be framed.
 *
 * Styles are inline only (the page is one document with its own <style>, and
 * the platform's typeface rules are inlined into it). Images are data URIs —
 * nothing on the page can fetch an address of its own choosing, on another
 * host (a tracking pixel) or on this one (a GET that acts as the trainee).
 * Fonts come from the platform's /fonts/ folder and nowhere else. What CSP
 * cannot stop — a <meta http-equiv="refresh"> that leaves the page — is
 * refused when the page is saved (GuidePageSafety).
 *
 * The owner chose this (D-127: content scripts are refused): the guide is a document, and its
 * only behaviour — copying a code block — is the platform's.
 *
 * @see D-127 · CONSTITUTION Art. 24 · PRD §12.3
 */
final class GuideDocument
{
    public function respond(string $html): Response
    {
        $nonce = Str::random(32);

        return response($this->prepare($html, $nonce), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => $this->policy($nonce),
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function policy(string $nonce): string
    {
        $fonts = rtrim(asset('fonts'), '/').'/';

        return implode('; ', [
            "default-src 'none'",
            "base-uri 'none'",
            "object-src 'none'",
            "frame-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'none'",
            "script-src 'nonce-{$nonce}'",
            "style-src 'unsafe-inline'",
            'img-src data: '.asset('brand/icons/favicon-mark.svg'),
            'font-src '.$fonts,
            "connect-src 'none'",
            "media-src 'none'",
            "manifest-src 'none'",
            "worker-src 'none'",
        ]);
    }

    /** The page with the platform's typeface and copy script placed first in its <head>. */
    public function prepare(string $html, string $nonce): string
    {
        $head = $this->head($nonce);

        if (preg_match('/<head\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            $at = $match[0][1] + strlen($match[0][0]);

            return substr($html, 0, $at).$head.substr($html, $at);
        }

        return $head.$html;
    }

    private function head(string $nonce): string
    {
        // Comments stripped: the injected head then carries no apostrophe
        // before the nonce, so a quote the page leaves open cannot end
        // inside it and turn the nonce into an attribute of the page's own
        // markup (security review, D-127).
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/guide-page.css')));
        $js = (string) file_get_contents(resource_path('js/guide-page.js'));

        return '<meta name="robots" content="noindex, nofollow">'
            .'<link rel="icon" type="image/svg+xml" href="'.e(asset('brand/icons/favicon-mark.svg')).'">'
            .'<style>'.str_ireplace('</style', '<\/style', $css).'</style>'
            .'<script nonce="'.e($nonce).'"'
            .' data-copied="'.e((string) __('project.guide.copied')).'"'
            .' data-press="'.e((string) __('project.guide.press_to_copy')).'">'
            .str_ireplace('</script', '<\/script', $js)
            .'</script>';
    }
}
