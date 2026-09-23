<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Extracts a clean, verified Zoom recording URL from whatever a trainer or
 * coordinator pastes: a plain link, or the `<iframe>` snippet Zoom's own
 * "Copy Embed Code" button produces.
 *
 * WHY THIS EXISTS
 * The request was literally "paste Zoom's embed code and have the video play
 * inside the platform". Storing and rendering that HTML verbatim is exactly
 * the classic stored-XSS shape Constitution Article 24 forbids — `{!! !!}`
 * on anything a user typed, whoever that user is. This is the boundary that
 * makes the request safe to grant: extract the URL the snippet points at,
 * verify it actually names a `zoom.us` host, and hand back a plain string.
 * Nothing here is ever rendered as HTML; the view builds its own `<iframe>`
 * from the returned string (`recording_url` stays a plain column).
 *
 * @see CONSTITUTION Article 24 · D-107
 */
final class ZoomRecordingUrl
{
    /**
     * @return string|null the clean https://…zoom.us/… url, or null when the
     *                      input is empty, unparsable, or not a Zoom host
     */
    public static function extract(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $candidate = self::srcFromIframe($raw) ?? $raw;
        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5));

        return self::isZoomHttpsUrl($candidate) ? $candidate : null;
    }

    /**
     * Pulls the `src="…"` (or `src='…'`) attribute out of an `<iframe>` tag.
     * Regex, not a DOM parser: the input is never trusted enough to hand to
     * one, and all that is needed is one attribute value, not a document.
     */
    private static function srcFromIframe(string $raw): ?string
    {
        if (! str_contains(mb_strtolower($raw), '<iframe')) {
            return null;
        }

        if (preg_match('/\bsrc\s*=\s*"([^"]+)"/i', $raw, $match) === 1) {
            return $match[1];
        }

        if (preg_match("/\\bsrc\\s*=\\s*'([^']+)'/i", $raw, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * https only, and a host that is exactly `zoom.us` or ends with
     * `.zoom.us` (Zoom serves recordings from regional subdomains such as
     * `us02web.zoom.us`) — never a substring match, which `zoom.us.evil.tld`
     * or `notzoom.us` would both slip through.
     */
    private static function isZoomHttpsUrl(string $candidate): bool
    {
        $parts = parse_url($candidate);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if ($parts['scheme'] !== 'https') {
            return false;
        }

        $host = mb_strtolower($parts['host']);

        return $host === 'zoom.us' || str_ends_with($host, '.zoom.us');
    }
}
