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
 * Whether the register should allow a replacement row at all is an open decision (D-149).
 * What is fixed here does not depend on it: a reissue that cannot be completed changes
 * nothing, and a certificate is revoked once.
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

function revokeAsBrowser(string $certificateId, string $reason): TestResponse
{
    $back = route('admin.certificates.index', ['cohort' => test()->cohort->id, 'revoke' => $certificateId]);

    $html = test()->actingAs(test()->admin)->get($back)->assertOk()->getContent();

    [$action, $fields] = browserForm($html, '//form[.//textarea[@name="revoke_reason"]]', ['revoke_reason' => $reason]);

    Auth::forgetGuards();

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
    $this->certificate->update(['revoked_at' => riyadhAt('2026-11-10 09:00:00')]);
    $before = $this->certificate->fresh()->revoked_at;

    revokeAsBrowser($this->certificate->id, 'Trying to revoke it once more.')
        ->assertSessionHasErrors('certificate');

    expect($this->certificate->fresh()->revoked_at->equalTo($before))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'certificate.revoked')->count())->toBe(0);
});

it('BR-25: إعادة إصدار لا تكتمل لا تغيّر شيئًا — لا ختم سحب ثانٍ ولا سطر تدقيق ولا صف جديد', function (): void {
    $this->certificate->update(['revoked_at' => riyadhAt('2026-11-10 09:00:00')]);
    $before = $this->certificate->fresh()->revoked_at;
    $rows = AuditLog::query()->count();

    $this->actingAs($this->admin)->post(route('admin.certificates.reissue', $this->certificate));

    expect(Certificate::query()->count())->toBe(1)
        ->and($this->certificate->fresh()->revoked_at->equalTo($before))->toBeTrue()
        ->and(AuditLog::query()->count())->toBe($rows);
});

it('BR-25: إعادة إصدار شهادة سارية لا تفشل نصفها — إن تعذّرت بقيت سارية', function (): void {
    $this->actingAs($this->admin)->post(route('admin.certificates.reissue', $this->certificate));

    // Today the register cannot hold a replacement (D-149); the point is that the old
    // certificate is not left revoked with nothing in its place.
    if (Certificate::query()->count() === 1) {
        expect($this->certificate->fresh()->revoked_at)->toBeNull()
            ->and(AuditLog::query()->where('action', 'certificate.revoked')->count())->toBe(0);
    } else {
        expect($this->certificate->fresh()->revoked_at)->not->toBeNull();
    }
});
