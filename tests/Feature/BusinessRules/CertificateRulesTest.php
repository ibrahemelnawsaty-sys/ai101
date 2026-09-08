<?php

declare(strict_types=1);

/**
 * BR-25, BR-26: the public verification page reveals nothing beyond what PRD §9.17
 * lists, and a certificate is issued only when both conditions are met together.
 *
 * Route names are the ones routes/web.php declares: the admin issues at
 * `admin.certificates.issue` (POST /admin/certificates) and revokes at
 * `admin.certificates.revoke`. PROJECT-CONTRACT.md §10 collapses the admin area
 * into `admin.*` and names no leaf, so web.php is the authority on the leaves.
 *
 * @see BR-25, BR-26 · PRD §9.6, §9.17 · PROJECT-CONTRACT.md §8
 */

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\DigitalCard;
use App\Models\Profile;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-11-20 12:00:00'));

    $this->cohort = makeCohort(['pass_score' => 60, 'min_attendance_rate' => 75]);
    $this->admin = makeAdmin();
    $this->participant = makeParticipant($this->cohort);

    $this->profile = Profile::factory()->create([
        'user_id' => $this->participant->id,
        'phone' => '0501234567',
        'city' => 'CANARY-CITY',
        'birth_date' => '1998-04-11',
    ]);
});

/*
|--------------------------------------------------------------------------
| BR-25 — the public pages leak nothing personal
|--------------------------------------------------------------------------
*/

it('BR-25: صفحة التحقق من البطاقة الرقمية لا تعرض أي بيانات شخصية حساسة', function (): void {
    $card = DigitalCard::factory()->create([
        'user_id' => $this->participant->id,
        'cohort_id' => $this->cohort->id,
        'issued_at' => riyadhAt('2026-10-01 09:00:00'),
        'revoked_at' => null,
    ]);

    $body = $this->get(route('card.verify', $card->qr_token))->assertOk()->getContent();

    foreach ([$this->participant->email, $this->profile->phone, 'CANARY-CITY', '1998-04-11', $this->participant->id] as $secret) {
        expect($body)->not->toContain((string) $secret);
    }
});

it('BR-25: صفحة التحقق من الشهادة تعرض ما نصّت عليه الوثيقة ولا شيء غيره', function (): void {
    $certificate = issueCertificateFor($this->participant, $this->cohort, ['final_score' => 88.5]);

    $body = $this->get(route('certificate.verify', $certificate->verify_code))->assertOk()->getContent();

    // Allowed: the holder's name, the programme, the cohort, the date, the status.
    expect($body)->toContain($this->profile->first_name_ar)
        ->and($body)->toContain($this->profile->last_name_ar);

    // Not allowed: anything else about the person or their performance.
    foreach ([$this->participant->email, $this->profile->phone, 'CANARY-CITY', $this->participant->id, '88.5'] as $secret) {
        expect($body)->not->toContain((string) $secret);
    }
});

it('BR-25: رمز التحقق ليس معرّف المستخدم ولا يمكن تخمينه منه', function (): void {
    $certificate = issueCertificateFor($this->participant, $this->cohort);

    expect($certificate->verify_code)->not->toBe($this->participant->id)
        ->and($certificate->verify_code)->not->toContain($this->participant->id)
        ->and(strlen((string) $certificate->verify_code))->toBeGreaterThanOrEqual(16);

    // An identifier in the URL must reach nothing. The page answers with its
    // named empty state rather than a 404 — CONSTITUTION art. 17 gives every
    // screen one — so what is asserted is the disclosure, not the status code.
    $body = $this->get(route('certificate.verify', $this->participant->id))->getContent();

    expect($body)->toContain(__('verify.certificate.not_found_title'))
        ->and($body)->not->toContain($this->profile->first_name_ar)
        ->and($body)->not->toContain($this->participant->email);
});

it('BR-25: صفحة التحقق من شهادة مسحوبة تعلن أنها ملغاة ولا تعرضها صحيحة', function (): void {
    $certificate = issueCertificateFor($this->participant, $this->cohort);
    $certificate->update(['revoked_at' => riyadhAt('2026-11-21 09:00:00')]);

    $this->get(route('certificate.verify', $certificate->verify_code))
        ->assertOk()
        ->assertSee('data-state="revoked"', escape: false);
});

it('BR-25: رمز تحقق غير موجود لا يكشف شيئًا', function (): void {
    $body = $this->get(route('certificate.verify', 'not-a-real-verify-code'))->getContent();

    expect($body)->not->toContain($this->participant->email);
});

/*
|--------------------------------------------------------------------------
| BR-26 — both conditions, together
|--------------------------------------------------------------------------
*/

it('BR-26: إصدار شهادة لمن لم يستوفِ نسبة الحضور مرفوض', function (): void {
    attendSessions($this->cohort, $this->participant, 8, 2);
    awardFinalScore($this->cohort, $this->participant, 95.0);

    assertRefused($this->actingAs($this->admin)->post(route('admin.certificates.issue'), [
        'user_id' => $this->participant->id,
        'cohort_id' => $this->cohort->id,
    ]));

    expect(Certificate::query()->count())->toBe(0);
});

it('BR-26: إصدار شهادة لمن لم يستوفِ درجة النجاح مرفوض', function (): void {
    attendSessions($this->cohort, $this->participant, 8, 8);
    awardFinalScore($this->cohort, $this->participant, 40.0);

    assertRefused($this->actingAs($this->admin)->post(route('admin.certificates.issue'), [
        'user_id' => $this->participant->id,
        'cohort_id' => $this->cohort->id,
    ]));

    expect(Certificate::query()->count())->toBe(0);
});

it('BR-26: استيفاء الشرطين معًا يُصدر الشهادة برقم تسلسلي ورمز تحقق', function (): void {
    attendSessions($this->cohort, $this->participant, 8, 6);
    awardFinalScore($this->cohort, $this->participant, 60.0);

    assertAccepted($this->actingAs($this->admin)->post(route('admin.certificates.issue'), [
        'user_id' => $this->participant->id,
        'cohort_id' => $this->cohort->id,
    ]));

    $certificate = Certificate::query()->sole();

    expect($certificate->serial_number)->toMatch('/^ATHAR-[A-Z0-9]+-\d{4}-\d{4,}$/')
        ->and($certificate->serial_number)->toUseLatinNumerals()
        ->and($certificate->verify_code)->not->toBeNull()
        ->and($certificate->issued_by)->toBe($this->admin->id);
});

it('BR-26: التجاوز اليدوي من المدير ممكن ويُسجَّل سببه في سجل التدقيق', function (): void {
    attendSessions($this->cohort, $this->participant, 8, 2);
    awardFinalScore($this->cohort, $this->participant, 30.0);

    assertAccepted($this->actingAs($this->admin)->post(route('admin.certificates.issue'), [
        'user_id' => $this->participant->id,
        'cohort_id' => $this->cohort->id,
        'override' => true,
        'override_reason' => 'The trainee completed an approved make-up track offline.',
    ]));

    expect(Certificate::query()->count())->toBe(1);

    $log = AuditLog::query()->where('entity_type', 'certificate')->sole();

    expect($log->actor_id)->toBe($this->admin->id)
        ->and(json_encode($log->after))->toContain('make-up track');
});

it('BR-26: التجاوز اليدوي بلا سبب مرفوض', function (): void {
    attendSessions($this->cohort, $this->participant, 8, 2);
    awardFinalScore($this->cohort, $this->participant, 30.0);

    assertRefused($this->actingAs($this->admin)->post(route('admin.certificates.issue'), [
        'user_id' => $this->participant->id,
        'cohort_id' => $this->cohort->id,
        'override' => true,
    ]));

    expect(Certificate::query()->count())->toBe(0);
});

it('BR-26: المدرب لا يصدر الشهادات', function (): void {
    $trainer = makeTrainer($this->cohort);
    attendSessions($this->cohort, $this->participant, 8, 8);
    awardFinalScore($this->cohort, $this->participant, 90.0);

    $this->actingAs($trainer)
        ->post(route('admin.certificates.issue'), [
            'user_id' => $this->participant->id,
            'cohort_id' => $this->cohort->id,
        ])
        ->assertForbidden();

    expect(Certificate::query()->count())->toBe(0);
});

it('BR-26: الشهادة لا تُصدر مرتين للمتدرب نفسه في الدفعة نفسها', function (): void {
    attendSessions($this->cohort, $this->participant, 8, 8);
    awardFinalScore($this->cohort, $this->participant, 90.0);

    assertAccepted($this->actingAs($this->admin)->post(route('admin.certificates.issue'), [
        'user_id' => $this->participant->id,
        'cohort_id' => $this->cohort->id,
    ]));

    assertRefused($this->actingAs($this->admin)->post(route('admin.certificates.issue'), [
        'user_id' => $this->participant->id,
        'cohort_id' => $this->cohort->id,
    ]));

    expect(Certificate::query()->count())->toBe(1);
});

it('BR-26: سحب الشهادة لا يحذف السطر بل يوسمه ملغى', function (): void {
    $certificate = issueCertificateFor($this->participant, $this->cohort);

    // The field is `revoke_reason`, matching RevokeCertificateRequest and the
    // `override_reason` of its sibling — PRD §9.17 requires the reason, the
    // codebase names it.
    assertAccepted($this->actingAs($this->admin)->delete(route('admin.certificates.revoke', $certificate), [
        'revoke_reason' => 'The certificate was issued against the wrong cohort record.',
    ]));

    expect(Certificate::query()->count())->toBe(1)
        ->and($certificate->fresh()->revoked_at)->not->toBeNull();
});
