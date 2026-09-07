<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the request locale from an allow-list and publishes the matching
 * text direction to every view, so `<html lang dir>` is never hard-coded.
 * Arabic RTL is the default and the fallback for anything unrecognised.
 *
 * @see BR-36 · PRD §13.4 · CONSTITUTION Art. 16
 */
final class SetLocaleAndDirection
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supported */
        $supported = (array) config('athar.locales.supported', ['ar', 'en']);
        $fallback = (string) config('athar.locales.default', 'ar');
        /** @var list<string> $rtl */
        $rtl = (array) config('athar.locales.rtl', ['ar']);

        $requested = $request->session()->get('locale');
        $locale = is_string($requested) && in_array($requested, $supported, true)
            ? $requested
            : $fallback;

        App::setLocale($locale);

        $direction = in_array($locale, $rtl, true) ? 'rtl' : 'ltr';

        $request->attributes->set('athar.locale', $locale);
        $request->attributes->set('athar.direction', $direction);

        View::share('locale', $locale);
        View::share('direction', $direction);

        return $next($request);
    }
}
