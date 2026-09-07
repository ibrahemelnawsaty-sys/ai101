<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Where an error page is allowed to send a visitor.
 *
 * The error views used to work this out themselves, in a raw PHP island each:
 * which routes exist, whether anyone is signed in, where "back" leads, and how
 * long a rate limiter wants the visitor to wait. Article 13 item 13 does not
 * allow a template to decide any of that, so the whole decision lives here and
 * the views only render the answer.
 *
 * Every link is guarded with Route::has(): an error page may be rendering
 * because the router, the session store or the database is the thing that
 * failed, so it resolves no model and assumes no route (CONSTITUTION art. 7).
 * The suggestion lists never differ by anything except "is anyone signed in",
 * so no page can leak whether a record exists (BR-22, BR-23, art. 22).
 *
 * @see PRD §8, §11.1, §12.4 · BR-07, BR-22, BR-23, BR-30
 * @see CONSTITUTION.md Articles 7, 13, 15, 22, 24
 */
final class ErrorNavigation
{
    /** The longest wait this page will print, in seconds. */
    public const MAX_RETRY_SECONDS = 3600;

    /**
     * Suggested links per status code, as [route name, translation key] pairs.
     * 'user' is used when a session is present, 'guest' otherwise.
     *
     * @var array<string, array{user: list<array{0: string, 1: string}>, guest: list<array{0: string, 1: string}>}>
     */
    private const SUGGESTIONS = [
        '401' => [
            'user' => [['register', 'auth.register.title'], ['password.request', 'auth.forgot.title'], ['home', 'nav.breadcrumb.home']],
            'guest' => [['register', 'auth.register.title'], ['password.request', 'auth.forgot.title'], ['home', 'nav.breadcrumb.home']],
        ],
        '403' => [
            'user' => [['dashboard', 'nav.participant.dashboard'], ['schedule', 'nav.participant.schedule'], ['messages.index', 'nav.participant.messages'], ['profile', 'nav.participant.profile']],
            'guest' => [],
        ],
        '404' => [
            'user' => [['dashboard', 'nav.participant.dashboard'], ['schedule', 'nav.participant.schedule'], ['attendance.index', 'nav.participant.attendance'], ['assignments.index', 'nav.participant.assignments'], ['resources.index', 'nav.participant.resources'], ['messages.index', 'nav.participant.messages']],
            'guest' => [['home', 'nav.breadcrumb.home'], ['login', 'auth.login.title'], ['register', 'auth.register.title']],
        ],
        '405' => [
            'user' => [['dashboard', 'nav.participant.dashboard'], ['schedule', 'nav.participant.schedule'], ['assignments.index', 'nav.participant.assignments']],
            'guest' => [['home', 'nav.breadcrumb.home'], ['login', 'auth.login.title']],
        ],
        '419' => [
            'user' => [['dashboard', 'nav.participant.dashboard'], ['login', 'auth.login.title']],
            'guest' => [['login', 'auth.login.title']],
        ],
        '429' => [
            'user' => [['home', 'nav.breadcrumb.home'], ['dashboard', 'nav.participant.dashboard']],
            'guest' => [['home', 'nav.breadcrumb.home']],
        ],
        '500' => [
            'user' => [['login', 'auth.login.title']],
            'guest' => [['login', 'auth.login.title']],
        ],
        '503' => ['user' => [], 'guest' => []],
    ];

    /** Codes whose primary action returns the visitor to the page they came from. */
    private const BACK_CODES = ['405', '419', '429'];

    /** Codes whose action label softens to "back home" for a visitor with no session. */
    private const ROLE_AWARE_LABEL_CODES = ['403', '404'];

    public static function hasSession(): bool
    {
        return Auth::hasUser();
    }

    /**
     * A named route's URL, or null when that route does not exist yet.
     */
    public static function routeUrl(string $name): ?string
    {
        return Route::has($name) ? route($name) : null;
    }

    public static function home(): string
    {
        return self::routeUrl('home') ?? url('/');
    }

    /**
     * The dashboard, but only for a visitor who actually has a session.
     */
    public static function dashboard(): ?string
    {
        return self::hasSession() ? self::routeUrl('dashboard') : null;
    }

    /**
     * The safest destination that certainly exists.
     */
    public static function primary(): string
    {
        return self::dashboard() ?? self::home();
    }

    /**
     * The page the visitor came from, when it is neither empty nor this page.
     */
    public static function back(): string
    {
        $previous = url()->previous();

        return $previous !== '' && $previous !== url()->current() ? $previous : self::primary();
    }

    /**
     * The primary action target for a status code.
     */
    public static function actionHref(string $code): string
    {
        if ($code === '401') {
            return self::routeUrl('login') ?? self::home();
        }

        if (in_array($code, self::BACK_CODES, true)) {
            return self::back();
        }

        if (in_array($code, self::ROLE_AWARE_LABEL_CODES, true)) {
            return self::dashboard() ?? self::home();
        }

        return self::home();
    }

    /**
     * The primary action label key for a status code. Two pages soften their
     * label for a visitor with no session, because "back to your dashboard" is
     * a promise the platform cannot keep for them.
     */
    public static function actionKey(string $code): string
    {
        if (in_array($code, self::ROLE_AWARE_LABEL_CODES, true) && self::dashboard() === null) {
            return 'auth.shared.back_home';
        }

        return 'errors.pages.'.$code.'.action';
    }

    /**
     * The suggestion links for a status code, skipping every route that does
     * not exist in this build.
     *
     * @return list<array{label: string, href: string}>
     */
    public static function suggestions(string $code): array
    {
        $table = self::SUGGESTIONS[$code] ?? ['user' => [], 'guest' => []];
        $candidates = self::hasSession() ? $table['user'] : $table['guest'];

        $links = [];

        foreach ($candidates as [$name, $key]) {
            $href = self::routeUrl($name);

            if ($href !== null) {
                $links[] = ['label' => (string) __($key), 'href' => $href];
            }
        }

        return $links;
    }

    /**
     * The wait a rate limiter asked for, as mm:ss.
     *
     * The figure comes from the Retry-After header the limiter itself set, so
     * it is the server's clock and never the browser's (BR-07). Anything
     * absent, non-numeric, zero or implausibly long is simply not shown.
     */
    public static function retryAfter(mixed $exception): ?string
    {
        if (! is_object($exception) || ! method_exists($exception, 'getHeaders')) {
            return null;
        }

        $header = $exception->getHeaders()['Retry-After'] ?? null;

        if (! is_numeric($header)) {
            return null;
        }

        $seconds = min(self::MAX_RETRY_SECONDS, max(0, (int) $header));

        if ($seconds === 0) {
            return null;
        }

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
