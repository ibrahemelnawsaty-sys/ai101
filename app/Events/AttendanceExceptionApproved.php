<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\AttendanceExceptionType;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Staff approved a participant's excuse request: the attendance record now
 * carries an excuse alongside whatever status the door recorded (D-106).
 */
final class AttendanceExceptionApproved
{
    use Dispatchable;

    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $sessionTitle,
        public readonly AttendanceExceptionType $type,
    ) {}
}
