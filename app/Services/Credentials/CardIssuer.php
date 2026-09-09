<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Models\DigitalCard;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Support\Str;

/**
 * Issues the digital participant card.
 *
 * WHY THIS CLASS DID NOT EXIST
 * The card screen has always told a trainee, in its own empty state:
 *
 *     "تُنشأ البطاقة تلقائيًا فور تفعيل حسابك والتحاقك بالدفعة"
 *
 * and nothing in `app/` ever created one. `DigitalCard` was written, its table
 * was migrated, its presenter renders a QR, its public verification page works
 * — and the only code that ever inserted a row was the factory the tests use
 * and the demo seeder. A real trainee who verified their account and enrolled
 * got the empty state, forever, under a sentence promising otherwise.
 *
 * It is the same shape as the reset link that was never sent and the four
 * events dispatched into an empty room: a screen promising an automatic action
 * that no code performs.
 *
 * IDEMPOTENT BY DESIGN. `(user_id, cohort_id)` is unique, and this is called
 * from an event listener that may be retried by the queue. Issuing twice must
 * be a no-op that returns the existing card, not a duplicate-key crash.
 *
 * @see BR-25 · PRD §7.6, §9.6 · CONSTITUTION.md Article 8 · D-53
 */
final class CardIssuer
{
    /** Long enough that the public verification URL cannot be guessed (BR-25). */
    private const TOKEN_LENGTH = 64;

    /**
     * Issue the card for an active enrolment, or hand back the one that exists.
     *
     * Returns null when the person is not an active participant anywhere: a
     * card names a cohort, so there is nothing to issue until there is one.
     */
    public function issueFor(User $user): ?DigitalCard
    {
        $cohortId = $this->activeCohortId($user);

        if ($cohortId === null) {
            return null;
        }

        /** @var DigitalCard|null $existing */
        $existing = DigitalCard::query()
            ->where('user_id', $user->getKey())
            ->where('cohort_id', $cohortId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var DigitalCard $card */
        $card = DigitalCard::query()->create([
            'user_id' => $user->getKey(),
            'cohort_id' => $cohortId,
            'card_number' => $this->nextNumber(),
            // Lower-case random, matching the shape the factory and the
            // verification route already expect.
            'qr_token' => Str::lower(Str::random(self::TOKEN_LENGTH)),
            'issued_at' => Clock::now(),
            'revoked_at' => null,
        ]);

        return $card;
    }

    /**
     * The cohort this person is actively enrolled in as a participant.
     *
     * Scoped on both role and status: a trainer attached to a cohort is not a
     * participant in it, and a withdrawn enrolment is not a place to hold a
     * card for.
     */
    private function activeCohortId(User $user): ?string
    {
        $cohortId = Enrollment::query()
            ->where('user_id', $user->getKey())
            ->where('role_in_cohort', EnrollmentRole::Participant->value)
            ->where('status', EnrollmentStatus::Active->value)
            ->orderByDesc('created_at')
            ->value('cohort_id');

        return is_string($cohortId) ? $cohortId : null;
    }

    /**
     * The next card number, in the prefix the certificate serials already use.
     *
     * Counting rows would repeat a number the moment one is removed, so the
     * sequence is taken from the highest number actually issued. The unique
     * index is the real guarantee; this only has to avoid colliding with it in
     * the ordinary case.
     *
     * There is no `withTrashed()` here, and that is not an oversight: the
     * migration gives `digital_cards` a `deleted_at` column but the model does
     * NOT use SoftDeletes, so the scope does not exist and a removed card is
     * removed outright. Calling it threw at runtime the first time this ran on
     * a real server — the column and the model disagree, and the model is what
     * the query obeys.
     */
    private function nextNumber(): string
    {
        $prefix = (string) config('athar.program.code', 'AI101');

        $highest = DigitalCard::query()
            ->where('card_number', 'like', $prefix.'-%')
            ->orderByDesc('card_number')
            ->value('card_number');

        $sequence = is_string($highest) && preg_match('/(\d+)$/', $highest, $m) === 1
            ? ((int) $m[1]) + 1
            : 1;

        return sprintf('%s-%05d', $prefix, $sequence);
    }
}
