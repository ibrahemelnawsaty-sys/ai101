<?php

use App\Providers\RouteServiceProvider;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Route middleware aliases fixed by PROJECT-CONTRACT.md section 10.
        $middleware->alias([
            'role' => App\Http\Middleware\EnsureRole::class,
            'cohort.scope' => App\Http\Middleware\EnsureCohortScope::class,
            'not.impersonating' => App\Http\Middleware\BlockWhenImpersonating::class,
            'impersonation.readonly' => App\Http\Middleware\ImpersonationReadOnly::class,
        ]);

        // Applied to every web request, in this order:
        //   1. locale + direction, so <html lang dir> and __() are settled
        //      before anything renders (Article 16);
        //   2. the active-account gate, so a suspended account is turned away
        //      on its next request even on a route that names no role - PRD
        //      Â§4.4 invalidates the sessions of a disabled account
        //      immediately (BR-28);
        //   3. the read-only preview guard, so a route that forgot to ask for
        //      the alias is still covered - preview is enforced below the UI,
        //      never by it (Article 23, BR-33);
        //   3. the security headers, minted last so they wrap the final
        //      response (Article 24, PRD 12.3).
        $middleware->web(append: [
            App\Http\Middleware\SetLocaleAndDirection::class,
            App\Http\Middleware\EnsureActiveAccount::class,
            App\Http\Middleware\ImpersonationReadOnly::class,
            App\Http\Middleware\SecurityHeaders::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(RouteServiceProvider::HOME);

        // Session cookies are encrypted, empty strings become null, and CSRF
        // is validated on every state-changing request (Article 24). Those are
        // the framework defaults and are deliberately left untouched.

        // NOTE - proxies are intentionally NOT trusted here. Forwarded headers
        // are spoofable, and audit_logs plus the IP rate limiters depend on a
        // truthful client address (Articles 8 and 22). Once the production
        // proxy ranges are confirmed they must be listed explicitly via
        // trustProxies(); until then the direct remote address is used.
    })
    /*
     * Event listeners are discovered from app/Listeners rather than listed.
     *
     * Stated explicitly instead of relying on the framework default, because
     * the default is exactly the kind of thing that is true until it is not —
     * and the failure mode here is silent: a listener that is never registered
     * does not error, it simply never runs. That is precisely what happened
     * before D-49, when four events were dispatched and app/Listeners did not
     * exist at all.
     */
    ->withEvents(discover: [__DIR__.'/../app/Listeners'])
    ->withSchedule(function (Schedule $schedule): void {
        // Shared hosting has no supervisor and no long-lived daemon
        // (Article 10). A single cPanel cron entry calls `schedule:run` every
        // minute and every background job flows through it.
        $schedule->command('queue:work --stop-when-empty --tries=3 --max-time=50 --sleep=0')
            ->everyMinute()
            ->withoutOverlapping(5);

        // PRD Â§9.9.5: a job runs every fifteen minutes and turns anyone who
        // never checked in to a finished session into `absent` (BR-08), anyone
        // who checked in but never out into `incomplete` and notifies the
        // trainer (BR-09), then recomputes the attendance rates. The command
        // existed and was never scheduled, so neither state was ever reached in
        // production. withoutOverlapping because a long catch-up run must not be
        // started twice by two cron ticks.
        $schedule->command('attendance:reconcile')
            ->everyFifteenMinutes()
            ->withoutOverlapping(14);

        $schedule->command('queue:prune-failed --hours=336')->weeklyOn(1, '03:10');
        $schedule->command('queue:prune-batches --hours=336')->weeklyOn(1, '03:20');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'token',
        ]);

        /**
         * Arabic error screens.
         *
         * Every user-facing error page lives in resources/views/errors and
         * takes its copy from lang/ar/errors.php - it says what happened and
         * what to do next, with no blame and no technical jargon (Article 15).
         *
         * Anything not matched here falls through to the framework, which
         * resolves resources/views/errors/{status}.blade.php on its own. That
         * is what renders 500 and 503 in production, where APP_DEBUG is false.
         */
        /**
         * PRD Â§4.3: every unauthorised attempt answers 403 AND lands in the
         * audit trail with the caller's IP. The middleware gates record their
         * own refusals (App\Http\Middleware\Concerns\LogsDenials) and a domain
         * refusal is recorded by the service that raised it; a policy saying no
         * inside a controller or a FormRequest was the one door left unlogged.
         *
         * Registered BEFORE the renderer below on purpose: this callback logs
         * and returns null, so the 403 page is still produced by the generic
         * handler that follows.
         */
        $exceptions->render(function (Throwable $exception, Request $request) {
            // A policy refusal reaches the render callbacks already mapped to
            // Symfony's AccessDeniedHttpException, with the AuthorizationException
            // as its previous. Both spellings are matched so the entry does not
            // depend on where in the framework the mapping happens. abort(403)
            // from a middleware raises a plain HttpException and is deliberately
            // NOT matched here: those gates log their own refusal already, and a
            // second row would make the trail lie about how many there were.
            $isPolicyDenial = $exception instanceof AccessDeniedHttpException
                || $exception instanceof AuthorizationException;

            if ($isPolicyDenial) {
                app(AuditLogger::class)->deniedRequest($request, 'policy.denied');
            }

            return null;
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            $status = match (true) {
                $exception instanceof AuthorizationException => 403,
                $exception instanceof ModelNotFoundException => 404,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => null,
            };

            if ($status === null || ! View::exists('errors.'.$status)) {
                return null;
            }

            return response()->view('errors.'.$status, ['status' => $status], $status);
        });
    })
    ->create();
