<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Notification;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * One line of the dashboard's latest-announcements card (PRD §9.5.3).
 *
 * Reading this card marks nothing: the dashboard never writes a read receipt,
 * which is also what keeps an account preview traceless (BR-34).
 *
 * @see BR-22, BR-34 · PRD §9.5.3, §9.16
 */
final class AnnouncementPresenter extends ViewModel
{
    public static function from(Notification $notification): self
    {
        return new self([
            'title' => (string) $notification->getAttribute('title'),
            'sentAt' => Present::toDateTime($notification->getAttribute('created_at')),
        ]);
    }
}
