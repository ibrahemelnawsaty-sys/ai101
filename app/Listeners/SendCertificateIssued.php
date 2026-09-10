<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CertificateIssued;
use App\Mail\AtharLetter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The letter at the end of the programme.
 *
 * The serial is shown in the detail strip because it is what the reader will
 * quote when somebody asks them to prove the certificate is real.
 *
 * @see BR-20, BR-25 · PRD §9.17, §9.16.1 · D-51
 */
final class SendCertificateIssued implements ShouldQueue
{
    public function handle(CertificateIssued $event): void
    {
        $address = (string) $event->user->getAttribute('email');

        if ($address === '') {
            return;
        }

        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.certificate_issued',
                values: [
                    'serial' => $event->serial,
                ],
                ctaUrl: $event->downloadUrl,
                // `certificates.serial` does not exist; the reader saw that
                // literal string beside their serial number (D-62).
                meta: ['certificates.serial_number' => $event->serial],
            ));
        } catch (\Throwable $exception) {
            // A letter that fails to leave changes nothing about the fact it was
            // announcing. Logged without the address or any personal data
            // (art. 12), and never rethrown.
            Log::warning('mail.certificate_issued_failed', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
