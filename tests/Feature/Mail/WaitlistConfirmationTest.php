<?php

declare(strict_types=1);

/**
 * Leaving an address on the landing page actually sends the letter it promises.
 *
 * WHY THIS SUITE EXISTS
 * `WaitlistJoined` was dispatched into an empty room. The event existed, the
 * copy at `emails.waitlist_confirmation` existed, the audit record was written —
 * and no listener was subscribed, so nothing was ever sent. The page said
 * "سنراسلك أول ما تُفتح الدفعة القادمة" and the platform had no way to.
 *
 * @see BR-30 · PRD §9.1.2, §9.16.1 · CONSTITUTION.md Article 7
 */

use App\Enums\CohortStatus;
use App\Mail\AtharLetter;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-01 20:00:01'));

    makeCohort([
        'status' => CohortStatus::Open->value,
        'capacity' => 30,
        'registration_closes_at' => riyadhAt('2026-10-01 20:00:00'),
    ]);
});

it('PRD §9.1.2: ترك البريد في قائمة الانتظار يُرسل رسالة تأكيد فعلًا', function (): void {
    Mail::fake();

    $this->post(route('waitlist.store'), ['email' => 'stranger@example.com'])
        ->assertRedirect(route('home'));

    Mail::assertQueued(AtharLetter::class, function (AtharLetter $letter): bool {
        return $letter->copyKey === 'emails.waitlist_confirmation'
            && $letter->hasTo('stranger@example.com');
    });
});

it('المادة 7: الرسالة لا تَعِد بزرّ ولا تنادي المستقبل باسم لا تعرفه', function (): void {
    Mail::fake();

    $this->post(route('waitlist.store'), ['email' => 'stranger@example.com']);

    Mail::assertQueued(AtharLetter::class, function (AtharLetter $letter): bool {
        // No account exists yet, so there is nothing to click and nobody to
        // greet by name. Inventing either would be the platform pretending.
        return $letter->ctaUrl === null && $letter->values === [];
    });
});

it('المادة 7: تعذّر الإرسال لا يُسقط الصفحة العامة', function (): void {
    // The audit record is already written by the time the letter is attempted,
    // so a mail outage must not become a 500 on a public form.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp is down'));

    $this->post(route('waitlist.store'), ['email' => 'stranger@example.com'])
        ->assertRedirect(route('home'))
        ->assertSessionHas('status', __('landing.waitlist.acknowledged'));
});
