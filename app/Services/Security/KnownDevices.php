<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\KnownDevice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * The browsers an account has signed in from — so a sign-in from a new one can
 * be told to its owner: the PRD §9.16.1 row "sign-in from a new device: the
 * account holder, at sign-in, e-mail".
 *
 * WHAT "A DEVICE" MEANS HERE — a temporary assumption awaiting sign-off (D-77):
 * a browser carrying the platform's own random cookie. Not an IP address,
 * which changes on every network, and not a user agent, which thousands of
 * browsers share. Clearing cookies makes a browser new again, which errs on
 * the side of telling the owner.
 *
 * Three behaviours that matter:
 *   · an account's FIRST device sends nothing — there is nothing to compare
 *     it against, and the first sign-in of every trainee would otherwise be a
 *     false alarm;
 *   · a browser's token is REUSED across accounts, never overwritten: two
 *     trainees taking turns on one lab computer each recognise it after their
 *     first sign-in there, instead of invalidating each other's token forever;
 *   · rows are written with insertOrIgnore against the unique index, so two
 *     simultaneous sign-ins cannot fail on it.
 *
 * Only a hash of the token is stored: a stolen table cannot be replayed as
 * cookies.
 *
 * @see PRD §9.16.1, §12.1 · D-77
 */
final class KnownDevices
{
    public const COOKIE = 'athar_device';

    /**
     * Record this sign-in's browser for the account.
     *
     * @return bool true when the owner should be told: a browser this account
     *              has not used before, on an account that has used another
     */
    public function recordSignIn(User $user, Request $request, CarbonImmutable $at): bool
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
        }

        // Queued on every sign-in, so the cookie's lifetime restarts with use.
        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $token,
            minutes: max(1, (int) config('athar.security.device_cookie_days')) * 24 * 60,
            path: '/',
            secure: (bool) config('session.secure'),
            httpOnly: true,
            sameSite: (string) config('session.same_site', 'lax'),
        ));

        $hash = hash('sha256', $token);
        $stamp = $at->format('Y-m-d H:i:s');

        $known = KnownDevice::query()
            ->where('user_id', $user->getKey())
            ->where('token_hash', $hash)
            ->exists();

        if ($known) {
            KnownDevice::query()
                ->where('user_id', $user->getKey())
                ->where('token_hash', $hash)
                ->update(['last_seen_at' => $stamp]);

            return false;
        }

        $hadAnother = KnownDevice::query()->where('user_id', $user->getKey())->exists();

        KnownDevice::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'token_hash' => $hash,
            'first_seen_at' => $stamp,
            'last_seen_at' => $stamp,
        ]);

        return $hadAnother;
    }
}
