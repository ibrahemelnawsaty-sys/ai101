<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Notification;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * One line of the notification centre (PRD §9.16).
 *
 * `type` is published as the severity bucket the icon chip is styled by
 * (.notif__ic--ok · --warn · --bad · --info in resources/css/screens.css), not
 * as the raw event name: the template needs a colour, and a colour is a
 * presentation decision that belongs on the server.
 *
 * The colour never carries the meaning on its own — every row also has its icon
 * and its own words (art. 18).
 *
 * Rendering this list marks nothing as read; the read receipt is a separate,
 * guarded action, and during an account preview it does not happen at all
 * (BR-34).
 *
 * @see BR-22, BR-34 · PRD §9.16, §9.16.1
 */
final class NotificationPresenter extends ViewModel
{
    /** Events that report something the participant must act on or worry about. */
    private const WARNING_TYPES = [
        'assignment_due_reminder',
        'attendance_low',
        'attendance_incomplete',
        'session_changed',
    ];

    /** Events that report a refusal or a cancellation. */
    private const BAD_TYPES = [
        'session_cancelled',
        'enrollment_rejected',
    ];

    /** Events that report something achieved. */
    private const GOOD_TYPES = [
        'certificate_issued',
        'enrollment_approved',
        'grade_recorded',
        'submission_received',
    ];

    public static function from(Notification $notification): self
    {
        $event = (string) $notification->getAttribute('type');
        $bucket = self::bucket($event);

        return new self([
            'type' => $bucket,
            'icon' => self::icon($event, $bucket),
            'title' => (string) $notification->getAttribute('title'),
            'body' => Present::text($notification->getAttribute('body')) ?? '',
            'targetUrl' => Present::text($notification->getAttribute('link')) ?? route('notifications'),
            'isRead' => (bool) $notification->getAttribute('is_read'),
            'createdAt' => Present::toDateTime($notification->getAttribute('created_at')),
        ]);
    }

    private static function bucket(string $event): string
    {
        if (in_array($event, self::BAD_TYPES, true)) {
            return 'bad';
        }

        if (in_array($event, self::WARNING_TYPES, true)) {
            return 'warn';
        }

        return in_array($event, self::GOOD_TYPES, true) ? 'ok' : 'info';
    }

    private static function icon(string $event, string $bucket): string
    {
        return match (true) {
            str_starts_with($event, 'session_') => 'video',
            str_starts_with($event, 'assignment_'), str_starts_with($event, 'submission_') => 'file',
            str_starts_with($event, 'grade_') => 'badge',
            str_starts_with($event, 'certificate_') => 'shield',
            str_starts_with($event, 'message_') => 'chat',
            $event === 'resource_added' => 'folder',
            $event === 'final_project_unlocked' => 'spark',
            $bucket === 'bad' || $bucket === 'warn' => 'warn',
            default => 'bell',
        };
    }
}
