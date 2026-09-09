<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EnrollmentApproved;
use App\Services\Credentials\CardIssuer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the promise the card screen has always made — the place is confirmed.
 *
 * The empty state on the card screen says the card is created automatically as
 * soon as an account is verified AND enrolled. Nothing created one: DigitalCard
 * was modelled, migrated, presented and publicly verifiable, and the only code
 * that ever inserted a row was the test factory and the demo seeder.
 *
 * There are two listeners because either half can happen last, and listening to
 * only one would leave whichever order is less common silently without a card —
 * which is the failure that was already there. They are separate classes rather
 * than two methods on one, because listener discovery resolves a listener by
 * the type its `handle` method accepts; a second method named anything else is
 * never registered and never runs.
 *
 * `CardIssuer::issueFor()` is idempotent, so both firing is harmless and a
 * queued retry does not produce a second card.
 *
 * @see BR-25 · PRD §7.6, §9.6 · D-53
 */
final class IssueCardOnEnrollment implements ShouldQueue
{
    public function __construct(private readonly CardIssuer $issuer) {}

    public function handle(EnrollmentApproved $event): void
    {
        try {
            // Null means verified-but-not-yet-enrolled (or the reverse). Not a
            // failure: the other half of the sentence has not happened, and the
            // other listener issues the card when it does.
            $this->issuer->issueFor($event->user);
        } catch (\Throwable $exception) {
            // A card that fails to issue must not undo a verification or an
            // enrolment. Logged without personal data (art. 12).
            Log::warning('card.issue_failed', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
