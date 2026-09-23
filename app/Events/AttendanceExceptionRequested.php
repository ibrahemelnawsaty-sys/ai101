<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\AttendanceExceptionType;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A participant asked to be excused for an absence or an unexcused lateness.
 *
 * @see D-106
 */
final class AttendanceExceptionRequested
{
    use Dispatchable;

    /** @see App\Events\EnrollmentApproved — same reason: no whole model on the queue. */
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $sessionTitle,
        public readonly AttendanceExceptionType $type,
    ) {}
}
