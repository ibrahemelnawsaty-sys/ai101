<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AccountVerified;
use App\Mail\AtharLetter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The welcome letter, sent the moment an account is actually usable.
 *
 * `AccountVerified` was one of the four events dispatched into an empty room.
 * The copy for this letter — `emails.welcome` — was written and never sent, so
 * a trainee who activated an account received nothing at all and had to work
 * out on their own that it had worked.
 *
 * It is sent on VERIFICATION, not on registration: a letter congratulating
 * somebody on joining, sent before they proved they own the address, is a
 * letter to a stranger.
 *
 * @see BR-29 · PRD §9.2.3, §9.16 · D-49
 */
final class SendAccountVerifiedWelcome implements ShouldQueue
{
    public function handle(AccountVerified $event): void
    {
        $address = (string) $event->user->getAttribute('email');

        if ($address === '') {
            return;
        }

        // There is no `activeCohort` relation on User — only `cohorts()`. A
        // freshly verified account is normally in exactly one, and a letter
        // must never fail because somebody is in none.
        $cohort = $event->user->cohorts()->first();

        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.welcome',
                values: [
                    'program' => (string) config('athar.program_name'),
                    'cohort' => $cohort?->getAttribute('name') ?? '',
                ],
                ctaUrl: route('dashboard'),
                // `nav.schedule` labelled a COHORT NAME as "the timetable".
                // AtharLetter now drops an empty value itself, so the
                // array_filter that used to guard it is no longer needed.
                meta: ['emails.common.cohort_label' => $cohort?->getAttribute('name') ?? ''],
                eyebrow: (string) config('athar.program_short_name'),
            ));
        } catch (\Throwable $exception) {
            // A welcome that fails to arrive is a delivery failure. The account
            // is verified and usable either way, and nothing is rolled back.
            Log::warning('mail.welcome_failed', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
