<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches the mandated response headers to every request:
 * Content-Security-Policy, Strict-Transport-Security, X-Content-Type-Options,
 * X-Frame-Options and Referrer-Policy.
 *
 * A fresh nonce is minted per request and shared with the views as `$cspNonce`,
 * so inline scripts carry `nonce="{{ $cspNonce }}"` instead of loosening
 * `script-src`. `style-src` keeps `'unsafe-inline'` deliberately: the motion
 * system sets inline style attributes, and inline CSS is a far smaller risk
 * than inline script — this must never be "fixed" by relaxing `script-src`.
 *
 * `script-src` also carries `'unsafe-eval'`, which the standard Alpine.js build
 * requires to evaluate its expressions. Dropping it means shipping the
 * `@alpinejs/csp` build instead; see the note raised with this slice.
 *
 * HSTS is emitted only over HTTPS, so local HTTP development is not poisoned.
 *
 * @see PRD §12.3 · CONSTITUTION Art. 24
 */
final class SecurityHeaders
{
    private const HSTS_SECONDS = 31536000;

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(24);

        $request->attributes->set('athar.csp_nonce', $nonce);
        View::share('cspNonce', $nonce);

        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');

        if ($request->isSecure()) {
            $headers->set(
                'Strict-Transport-Security',
                'max-age='.self::HSTS_SECONDS.'; includeSubDomains; preload'
            );
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "media-src 'self'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            'upgrade-insecure-requests',
        ];

        /** @var list<string> $extra */
        $extra = (array) config('athar.security.csp_extra', []);

        return implode('; ', array_merge($directives, $extra));
    }
}
