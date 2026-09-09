<?php

declare(strict_types=1);

/**
 * The digital card is issued, not merely described.
 *
 * WHY THIS SUITE EXISTS
 * The card screen's own empty state says, in Arabic, that the card is created
 * automatically as soon as an account is verified and enrolled. Nothing in
 * `app/` ever created one. `DigitalCard` was modelled, migrated, given a
 * presenter that renders a QR, and a public verification page that works — and
 * the only code that ever inserted a row was the test factory and the demo
 * seeder.
 *
 * A live check on the deployed platform, signed in as an enrolled trainee,
 * showed the empty state under that sentence. The screen was promising an
 * automatic action that no code performed — the same shape as the reset link
 * that was never sent (D-49) and the four events dispatched into an empty room.
 *
 * @see BR-25 · PRD §7.6, §9.6 · D-53
 */

use App\Events\AccountVerified;
use App\Events\EnrollmentApproved;
use App\Listeners\IssueCardOnEnrollment;
use App\Listeners\IssueCardOnVerification;
use App\Models\DigitalCard;
use App\Services\Credentials\CardIssuer;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));

    $this->cohort = makeCohort();
});

it('D-53: التحقق من الحساب يُصدر بطاقة لمن هو مسجَّل في دفعة', function (): void {
    $participant = makeParticipant($this->cohort);

    expect(DigitalCard::query()->where('user_id', $participant->getKey())->count())->toBe(0);

    (new IssueCardOnVerification(app(CardIssuer::class)))
        ->handle(new AccountVerified($participant));

    $card = DigitalCard::query()->where('user_id', $participant->getKey())->first();

    expect($card)->not->toBeNull()
        ->and((string) $card->getAttribute('cohort_id'))->toBe((string) $this->cohort->getKey())
        ->and((string) $card->getAttribute('card_number'))->toStartWith('AI101-')
        // The QR opens a public page, so the token must be long and random
        // rather than anything derived from a user id (BR-25).
        ->and(mb_strlen((string) $card->getAttribute('qr_token')))->toBe(64)
        ->and($card->getAttribute('revoked_at'))->toBeNull();
});

it('D-53: تأكيد الالتحاق يُصدر البطاقة أيضًا — أيّ النصفين وقع أخيرًا', function (): void {
    // The order that would have been silently card-less if only one event were
    // listened to.
    $participant = makeParticipant($this->cohort);

    (new IssueCardOnEnrollment(app(CardIssuer::class)))
        ->handle(new EnrollmentApproved($participant, 'CANARY-COHORT'));

    expect(DigitalCard::query()->where('user_id', $participant->getKey())->count())->toBe(1);
});

it('D-53: الإصدار مرتين لا يُنشئ بطاقتين', function (): void {
    // The listener is queued and may be retried, and both events can fire for
    // the same person. A duplicate-key crash here would fail a job forever.
    $participant = makeParticipant($this->cohort);
    $issuer = app(CardIssuer::class);

    $first = $issuer->issueFor($participant);
    $second = $issuer->issueFor($participant);

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and((string) $second->getKey())->toBe((string) $first->getKey())
        ->and(DigitalCard::query()->where('user_id', $participant->getKey())->count())->toBe(1);
});

it('D-53: لا بطاقة لمن ليس مسجَّلًا في دفعة', function (): void {
    // A card names a cohort. Until there is one there is nothing to issue, and
    // inventing a card without a cohort would put a blank on a screen that is
    // meant to be shown to a person as proof of enrolment.
    $stranger = makeUser('participant');

    expect(app(CardIssuer::class)->issueFor($stranger))->toBeNull()
        ->and(DigitalCard::query()->where('user_id', $stranger->getKey())->count())->toBe(0);
});

it('D-53: أرقام البطاقات لا تتكرّر', function (): void {
    $numbers = collect(range(1, 3))
        ->map(function () {
            $card = app(CardIssuer::class)->issueFor(makeParticipant($this->cohort));

            return (string) $card?->getAttribute('card_number');
        });

    expect($numbers->unique()->count())->toBe(3);
});
