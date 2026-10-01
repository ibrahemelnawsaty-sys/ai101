<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Named rate limiters, one per row of PRD §12.4.
 *
 * Routes reach them by name - `throttle:login`, `throttle:register`,
 * `throttle:password`, `throttle:password-admin`, `throttle:attendance`, `throttle:upload`,
 * `throttle:messages`, `throttle:public` - exactly as PROJECT-CONTRACT §10
 * spells them.
 *
 * Counters live in the `cache` store, which is the database on shared hosting
 * (art. 10). There is no Redis to assume.
 *
 * Exceeding a limiter raises a 429 that the handler renders as the Arabic
 * "too many attempts" screen; the limiter itself never composes a message, so
 * no Arabic string leaks into PHP (art. 15).
 *
 * @see BR-30 · PRD §12.4 · CONSTITUTION art. 24
 */
final class RouteServiceProvider extends ServiceProvider
{
    /**
     * Where an already-authenticated visitor is sent when they ask for a
     * guest-only screen.
     */
    public const HOME = '/dashboard';

    public function boot(): void
    {
        // 5 attempts per account per 15 minutes.
        // Keyed by the submitted address, never by whether that address
        // exists: the throttle must not become an account oracle (BR-30).
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinutes(15, 5)
            ->by($this->emailKey($request)));

        // 5 registrations per IP per hour.
        RateLimiter::for('register', fn (Request $request): Limit => Limit::perHour(5)
            ->by($this->addressKey($request)));

        // 3 password-reset requests per e-mail per hour.
        RateLimiter::for('password', fn (Request $request): Limit => Limit::perHour(3)
            ->by($this->emailKey($request)));

        // D-151 — the administrator's «send a recovery link» and «send the activation again»:
        // 3 an hour per (administrator, target account), shared by the two actions. The public
        // limiter above is keyed by the e-mail field, which these forms do not send, so it fell
        // back to the office's IP address: the third action of the hour from anyone, on any
        // account, blocked everyone for an hour, and a hidden `email` field dodged it. Nothing
        // here reads the request body.
        RateLimiter::for('password-admin', fn (Request $request): Limit => Limit::perHour(3)
            ->by($this->adminTargetKey($request)));

        // 10 check-in / check-out attempts per user per minute.
        RateLimiter::for('attendance', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($this->actorKey($request)));

        // 20 uploads per user per hour.
        RateLimiter::for('upload', fn (Request $request): Limit => Limit::perHour(20)
            ->by($this->actorKey($request)));

        // 30 messages per user per minute; a message carrying files counts
        // against 20 an hour instead, like every other upload (D-136, review of
        // 2-D: a message now writes up to three files of ten megabytes, and
        // thirty a minute could fill a shared-hosting disk). Plain text keeps its
        // own bucket, so a long conversation never meets the upload ceiling.
        RateLimiter::for('messages', fn (Request $request): Limit => $request->hasFile('attachments')
            ? Limit::perHour(20)->by('files|'.$this->actorKey($request))
            : Limit::perMinute(30)->by('lines|'.$this->actorKey($request)));

        // D-124 — a support ticket's lines: 30 a minute for text, like the
        // messages; a line carrying files counts against 20 an hour, like the
        // other uploads. A busy coordinator's notes never hit the upload
        // ceiling, and the files still do.
        RateLimiter::for('support', fn (Request $request): Limit => $request->hasFile('attachments')
            ? Limit::perHour(20)->by('files|'.$this->actorKey($request))
            : Limit::perMinute(30)->by('lines|'.$this->actorKey($request)));

        // 100 public reads per IP per minute - landing page, card and
        // certificate verification.
        RateLimiter::for('public', fn (Request $request): Limit => Limit::perMinute(100)
            ->by($this->addressKey($request)));
    }

    /**
     * Normalised e-mail key. Falls back to the address so a request with no
     * e-mail field is still counted rather than waved through (art. 7).
     */
    private function emailKey(Request $request): string
    {
        $email = $request->input('email');

        if (! is_string($email) || trim($email) === '') {
            return $this->addressKey($request);
        }

        return Str::lower(trim($email));
    }

    /**
     * The administrator and the account they act on (D-151). The route's `{user}` is read as the
     * router holds it: the id itself (throttling runs before the binding is substituted) or the
     * model when it already is one. An unauthenticated caller falls back to the address — such a
     * request is turned away by `auth` first, but a key is never left empty (art. 7).
     */
    private function adminTargetKey(Request $request): string
    {
        $target = $request->route('user');
        $targetId = $target instanceof Model ? (string) $target->getKey() : (string) $target;

        return $this->actorKey($request).'|target:'.$targetId;
    }

    /**
     * Per-user key, falling back to the address for unauthenticated callers.
     */
    private function actorKey(Request $request): string
    {
        $user = $request->user();

        return $user !== null
            ? 'user:'.(string) $user->getAuthIdentifier()
            : $this->addressKey($request);
    }

    private function addressKey(Request $request): string
    {
        return 'ip:'.($request->ip() ?? 'unknown');
    }
}
