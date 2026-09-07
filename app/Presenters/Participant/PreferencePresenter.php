<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\NotificationPreference;
use App\Support\ViewModel;

/**
 * One row of the notification preference table (PRD §9.4.1).
 *
 * Security notices are not switchable. PRD §9.4.1 keeps them arriving whatever
 * the table says, so both channels are published as locked for them rather than
 * offering a toggle the server would ignore (art. 5).
 *
 * @see BR-22, BR-33 · PRD §9.4.1, §9.16.1
 */
final class PreferencePresenter extends ViewModel
{
    /**
     * Event types that always reach the account, on both channels.
     *
     * @var list<string>
     */
    private const ALWAYS_ON = [
        'enrollment_approved',
        'enrollment_rejected',
        'certificate_issued',
    ];

    public static function from(NotificationPreference $preference): self
    {
        $key = (string) $preference->getAttribute('type');
        $editable = ! in_array($key, self::ALWAYS_ON, true);

        return new self([
            'key' => $key,
            'label' => (string) __('notifications.types.'.$key.'.label'),
            'description' => (string) __('notifications.types.'.$key.'.body'),
            'platform' => $editable ? (bool) $preference->getAttribute('in_app_enabled') : true,
            'platformEditable' => $editable,
            'email' => $editable ? (bool) $preference->getAttribute('email_enabled') : true,
            'emailEditable' => $editable,
        ]);
    }
}
