<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NewDeviceLogin;
use App\Mail\AtharLetter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * A security notice for a sign-in from an unfamiliar device.
 *
 * Uses the instant carried by the event, not the moment the queue happens to
 * run: a security letter that reports the wrong time is worse than none.
 *
 * @see BR-28 · PRD §9.3, §9.16.1 · D-51
 */
final class SendNewDeviceLogin implements ShouldQueue
{
    public function handle(NewDeviceLogin $event): void
    {
        $address = (string) $event->user->getAttribute('email');

        if ($address === '') {
            return;
        }

        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.new_device_login',
                values: [
                    'datetime' => \App\Support\Dates::dateTime($event->at),
                    'ip' => $event->ip,
                    'email' => (string) config('athar.email'),
                ],
                ctaUrl: route('profile.edit'),
            ));
        } catch (\Throwable $exception) {
            // A letter that fails to leave changes nothing about the fact it was
            // announcing. Logged without the address or any personal data
            // (art. 12), and never rethrown.
            Log::warning('mail.new_device_login_failed', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
