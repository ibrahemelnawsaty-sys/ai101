<?php

declare(strict_types=1);

namespace App\Services\Messages;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Whether somebody is using the platform right now — the "offline" of PRD
 * §9.16.1's "platform (and e-mail if offline)".
 *
 * Read from the web session table: the database session driver stamps
 * `last_activity` on every request, and an open conversation asks for new
 * messages every few seconds. So a member with any request in the last few
 * minutes is online and reads the message on the platform; nobody else is
 * (AMB-14). The window is `athar.messages.offline_after_minutes` (D-83).
 *
 * KNOWN LIMIT: an administrator's account preview signs in AS the trainee, so
 * for its thirty minutes the trainee reads as online and a message letter is
 * held back. The platform notice is written regardless, and the window claim
 * is not spent, so the next message after the preview is e-mailed. The
 * session payload is encrypted, so the preview marker cannot be filtered on
 * here (D-83).
 *
 * @see PRD §9.13.2, §9.16.1 · FR-NOTIF-22 · D-83
 */
final class Presence
{
    public function isOnline(string $userId, CarbonImmutable $at): bool
    {
        $window = max(0, (int) config('athar.messages.offline_after_minutes')) * 60;

        return DB::table((string) config('session.table'))
            ->where('user_id', $userId)
            ->where('last_activity', '>=', $at->getTimestamp() - $window)
            ->exists();
    }
}
