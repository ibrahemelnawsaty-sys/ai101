<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\AttendanceExceptionType;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Staff declined a participant's excuse request.
 *
 * The reason travels with the event because the letter must give one, exactly
 * as EnrollmentRejected already requires for a declined registration (art. 7).
 *
 * @see D-106
 */
final class AttendanceExceptionRejected
{
    use Dispatchable;

    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $sessionTitle,
        public readonly AttendanceExceptionType $type,
        public readonly string $reason,
    ) {}
}
