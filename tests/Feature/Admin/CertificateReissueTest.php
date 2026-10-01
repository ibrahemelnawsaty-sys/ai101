<?php

declare(strict_types=1);

/**
 * Phase 5 — what revoking and reissuing may never do to the register.
 *
 * Found by running the screen (D-147): «إعادة الإصدار» failed every time with «try again
 * shortly» — the table allows one certificate per person and cohort
 * (`unique(user_id, cohort_id)`), and a reissue writes a second row — and each failed
 * attempt still stamped `revoked_at` again and wrote a trail line, so the moment of the
 * real revocation was lost. Revoking an already revoked certificate did the same and said
 * «done».
 *
 * The owner decided (D-149, option A) that the register holds a replacement row: the
 * `unique(user_id, cohort_id)` key was replaced by a plain index, and «one live certificate per
 * person and cohort» is a rule the server checks inside the transaction that writes it
 * (CertificateRegisterKeyTest). What is held here: a reissue that cannot be completed changes
 * nothing, a certificate is revoked once, and the replacement is a new row with a new serial.
 *
 * @see BR-25 · PRD §9.17 · CONSTITUTION art. 8 · D-147, D-149
 */

use App\Models\AuditLog;
use App\Models\Certificate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-11-20 12:00:00'));

    $this->cohort = makeCohort(['status' => 'completed']);
    $this->admin = makeAdmin();
    $this->holder = makeParticipant($this->cohort);
    $this->certificate = issueCertificateFor($this->holder, $this->cohort);
});

/**
 * The revoke form as the page gives it, read while the certificate is live — and the post of it
 * held until the caller says (two tabs: what the second tab sends after the first has won).
 *
 * @return array{0: string, 1: array<string, mixed>, 2: string}
 */
function revokeFormOf(string $certificateId, string $reason): array
{
    $back = route('admin.certificates.index', ['cohort' => test()->cohort->id, 'revoke' => $certificateId]);

    $html = test()->actingAs(test()->admin)->get($back)->assertOk()->getContent();

    [$action, $fields] = browserForm($html, '//form[.//textarea[@name="revoke_reason"]]', ['revoke_reason' => $reason]);

    Auth::forgetGuards();

    return [$action, $fields, $back];
}

function revokeAsBrowser(string $certificateId, string $reason): TestResponse
{
    [$action, $fields, $back] = revokeFormOf($certificateId, $reason);

    return test()->actingAs(test()->admin)->from($back)->post($action, $fields);
}

it('BR-25: سحب الشهادة من نموذج الصفحة يختم وقت السحب ويدوّن سطرًا واحدًا', function (): void {
    revokeAsBrowser($this->certificate->id, 'Issued in error to the wrong person.')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('certificates.revoked'));

    $fresh = $this->certificate->fresh();

    expect($fresh->revoked_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'certificate.revoked')->count())->toBe(1);
});

it('BR-25: سحب شهادة مسحوبة يُرفض ولا يمسّ وقت السحب الأول ولا يضيف سطر تدقيق', function (): void {
    // The second tab: its form was read while the certificate was live, and the first tab won.
    [$action, $fields, $back] = revokeFormOf($this->certificate->id, 'Trying to revoke it once more.');

    $this->certificate->update(['revoked_at' => riyadhAt('2026-11-10 09:00:00')]);
    $before = $this->certificate->fresh()->revoked_at;

    $this->actingAs($this->admin)->from($back)->post($action, $fields)
        ->assertSessionHasErrors('certificate');

    expect($this->certificate->fresh()->revoked_at->equalTo($before))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'certificate.revoked')->count())->toBe(0);
});

it('BR-25: بعد السحب الناجح لا تعود لوحة السحب بزرّ حيّ على شهادة مسحوبة', function (): void {
    revokeAsBrowser($this->certificate->id, 'Issued in error to the wrong person.')->assertSessionHasNoErrors();

    $page = $this->actingAs($this->admin)
        ->get(route('admin.certificates.index', ['cohort' => $this->cohort->id, 'revoke' => $this->certificate->id]))
        ->assertOk()->getContent();

    expect($page)->not->toContain('name="revoke_reason"')
        ->and($page)->not->toContain(__('certificates.admin.revoke_warning'));
});

it('BR-25: إعادة إصدار لا تكتمل لا تغيّر شيئًا — لا ختم سحب ثانٍ ولا سطر تدقيق ولا صف جديد', function (): void {
    $this->certificate->update(['revoked_at' => riyadhAt('2026-11-10 09:00:00')]);
    $before = $this->certificate->fresh()->revoked_at;

    // Another certificate of the same person is already live, so a replacement cannot be written.
    issueCertificateFor($this->holder, $this->cohort, ['serial_number' => 'ATHAR-AI101-2026-9100', 'verify_code' => str_repeat('e', 40)]);
    $rows = AuditLog::query()->count();

    $this->actingAs($this->admin)->post(route('admin.certificates.reissue', $this->certificate))
        ->assertSessionHasErrors('certificate');

    expect(Certificate::query()->count())->toBe(2)
        ->and($this->certificate->fresh()->revoked_at->equalTo($before))->toBeTrue()
        ->and(AuditLog::query()->count())->toBe($rows);
});

it('BR-25: إعادة إصدار شهادة سارية تسحبها وتُصدر بديلًا برقم جديد في معاملة واحدة', function (): void {
    $this->actingAs($this->admin)->post(route('admin.certificates.reissue', $this->certificate))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('certificates.reissued'));

    $replacement = Certificate::query()->whereNull('revoked_at')->sole();

    expect(Certificate::query()->count())->toBe(2)
        ->and($replacement->id)->not->toBe($this->certificate->id)
        ->and($replacement->serial_number)->not->toBe($this->certificate->serial_number)
        ->and($replacement->verify_code)->not->toBe($this->certificate->verify_code)
        ->and($this->certificate->fresh()->revoked_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'certificate.revoked')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'certificate.issued')->count())->toBe(1);
});

it('BR-25: الرقم القديم يبقى بمعناه — رابط التحقق للمسحوبة «ملغاة» وللبديل «سارية»', function (): void {
    $oldCode = $this->certificate->verify_code;

    $this->actingAs($this->admin)->post(route('admin.certificates.reissue', $this->certificate))
        ->assertSessionHasNoErrors();

    $newCode = Certificate::query()->whereNull('revoked_at')->sole()->verify_code;

    Auth::forgetGuards();

    $old = $this->get(route('certificate.verify', ['code' => $oldCode]))->assertOk()->getContent();
    $new = $this->get(route('certificate.verify', ['code' => $newCode]))->assertOk()->getContent();

    expect($old)->toContain(e(__('certificates.revoked_title')))
        ->and($new)->not->toContain(e(__('certificates.revoked_title')));
});

/*
|--------------------------------------------------------------------------
| The register holds a replacement row (D-149)
|--------------------------------------------------------------------------
| Two rules that must hold now that the key no longer carries them: a certificate that is
| already revoked keeps the moment it was revoked, and a person never ends up holding two live
| certificates.
*/

it('BR-25: إعادة إصدار مسحوبة تحفظ وقت سحبها الأول وتُصدر واحدة سارية', function (): void {
    $this->certificate->update(['revoked_at' => riyadhAt('2026-11-10 09:00:00')]);
    $before = $this->certificate->fresh()->revoked_at;

    $this->actingAs($this->admin)->post(route('admin.certificates.reissue', $this->certificate))
        ->assertSessionHasNoErrors();

    expect(Certificate::query()->count())->toBe(2)
        ->and(Certificate::query()->whereNull('revoked_at')->count())->toBe(1)
        ->and($this->certificate->fresh()->revoked_at->equalTo($before))->toBeTrue()
        // No second «revoked» line for a certificate that was revoked long before.
        ->and(AuditLog::query()->where('action', 'certificate.revoked')->count())->toBe(0);
});

it('BR-26: لا تُعاد إصدار مسحوبة قديمة لمن لديه شهادة سارية — لا شهادتان ساريتان', function (): void {
    $this->certificate->update(['revoked_at' => riyadhAt('2026-11-10 09:00:00')]);
    $live = issueCertificateFor($this->holder, $this->cohort, ['serial_number' => 'ATHAR-AI101-2026-9999', 'verify_code' => 'live-code-'.str_repeat('x', 20)]);

    $this->actingAs($this->admin)->post(route('admin.certificates.reissue', $this->certificate))
        ->assertSessionHasErrors('certificate');

    expect(Certificate::query()->whereNull('revoked_at')->count())->toBe(1)
        ->and(Certificate::query()->count())->toBe(2)
        ->and($live->fresh()->revoked_at)->toBeNull();
});
