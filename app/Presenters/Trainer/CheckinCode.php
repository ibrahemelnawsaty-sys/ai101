<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Models\Session;
use App\Services\Time\Clock;
use App\Support\QrSvg;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * The self-check-in QR code shown on the coordinator's attendance screen
 * (D-106).
 *
 * WHY THE URL IS BUCKETED TO A TEN-MINUTE BOUNDARY
 * `URL::temporarySignedRoute()` is deterministic: the same route, the same
 * params and the same `expires` timestamp always sign to the same URL. Asking
 * for a fresh one on every poll (every few seconds, while the roster is live)
 * would make the image flicker constantly if each call used "now + 10
 * minutes" — every call would carry a different `expires` and therefore a
 * different signature. Rounding the expiry UP to the next ten-minute wall
 * clock boundary instead means every call inside the same ten-minute window
 * signs to the IDENTICAL url, and the url changes — "rotates" — only once
 * that boundary passes. No new table, no stored code: Laravel's own signature
 * and `expires` check is the entire rotation and expiry mechanism.
 *
 * @see D-106
 */
final class CheckinCode extends ViewModel
{
    public const BUCKET_SECONDS = 600;

    public static function for(Session $session): self
    {
        $bucketEndsAt = self::nextBucketBoundary();

        $url = URL::temporarySignedRoute(
            'attendance.selfCheckIn',
            $bucketEndsAt,
            ['session' => $session->getKey()],
        );

        return new self([
            'url' => $url,
            'svg' => QrSvg::of($url),
            'bucketEndsAtIso' => $bucketEndsAt->toIso8601ZuluString(),
        ]);
    }

    private static function nextBucketBoundary(): CarbonImmutable
    {
        $now = Clock::now();
        $bucket = self::BUCKET_SECONDS;
        $next = (intdiv($now->getTimestamp(), $bucket) + 1) * $bucket;

        return $now->setTimestamp($next);
    }
}
