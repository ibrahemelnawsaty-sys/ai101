<?php

declare(strict_types=1);

/**
 * Phase 5 · D-149 — the register holds a replacement certificate, and «one live certificate per
 * person and cohort» is a rule the server keeps inside the transaction that writes it.
 *
 * The table carried `unique(user_id, cohort_id)`. «Reissue» (and issuing again after a
 * revocation) writes a SECOND row for the same person and cohort — BR-25 keeps the revoked row so
 * its public link still says «revoked» — so every reissue failed, five times over, with advice to
 * try again shortly. The owner chose option A: a new migration replaces the unique key with a
 * plain index, and the guarantee moves to the application, checked under a row lock so two
 * requests for the same person queue up (the key used to stop the second one).
 *
 * Nothing here touches eligibility (BR-26), the serial number's meaning or the verification page
 * (BR-25): this is about how many LIVE rows a person can hold, and that it stays one.
 *
 * @see BR-25, BR-26 · PRD §7.7, §9.17 · CONSTITUTION art. 4, art. 29 · D-149
 */

use App\Models\AuditLog;
use App\Models\Certificate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-11-20 12:00:00'));

    $this->cohort = makeCohort(['status' => 'completed']);
    $this->admin = makeAdmin();
    $this->holder = makeParticipant($this->cohort);
});

/** @return list<array{name: string, columns: list<string>, unique: bool}> */
function certificateIndexes(): array
{
    return Schema::getIndexes('certificates');
}

function pairIndexes(): array
{
    return array_values(array_filter(
        certificateIndexes(),
        static fn (array $index): bool => $index['columns'] === ['user_id', 'cohort_id'],
    ));
}

it('D-149: لا مفتاح فريد على (المتدرب، الدفعة) في سجل الشهادات، بل فهرس عادي يخدم البحث', function (): void {
    $pair = pairIndexes();

    expect($pair)->not->toBeEmpty()
        ->and(array_filter($pair, static fn (array $index): bool => $index['unique']))->toBeEmpty()
        ->and(array_filter($pair, static fn (array $index): bool => ! $index['unique']))->not->toBeEmpty();
});

it('BR-25: الرقم التسلسلي ورمز التحقق يبقيان فريدين — الحماية التي لا تُمسّ', function (): void {
    $unique = collect(certificateIndexes())->where('unique', true)->pluck('columns')->all();

    expect($unique)->toContain(['serial_number'])
        ->and($unique)->toContain(['verify_code']);
});

/** The migration file this decision added. */
function registerKeyMigration(): object
{
    return require database_path('migrations/2026_10_01_100000_relax_certificate_register_key.php');
}

it('D-149: الترحيل يعمل في الاتجاهين — down يعيد المفتاح الفريد وup يزيله', function (): void {
    $migration = registerKeyMigration();

    $migration->down();

    expect(collect(pairIndexes())->where('unique', true))->not->toBeEmpty();

    $migration->up();

    expect(collect(pairIndexes())->where('unique', true))->toBeEmpty()
        ->and(collect(pairIndexes())->where('unique', false))->not->toBeEmpty();
});

it('D-149: down يرفض بوضوح إن كان في السجل شهادتان لشخص ودفعة ولا يحذف شيئًا', function (): void {
    issueCertificateFor($this->holder, $this->cohort, ['revoked_at' => riyadhAt('2026-11-10 09:00:00')]);
    issueCertificateFor($this->holder, $this->cohort);

    expect(fn () => registerKeyMigration()->down())->toThrow(RuntimeException::class, 'Nothing was changed.');

    expect(Certificate::query()->count())->toBe(2)
        ->and(collect(pairIndexes())->where('unique', true))->toBeEmpty();
});

it('BR-25: يُصدر لمن سُحبت شهادته بديل — صف ثانٍ في السجل، والمسحوبة تبقى مسحوبة', function (): void {
    $revoked = issueCertificateFor($this->holder, $this->cohort, ['revoked_at' => riyadhAt('2026-11-10 09:00:00')]);

    // The same write the screens make: a second row for the same person and cohort.
    $second = issueCertificateFor($this->holder, $this->cohort, ['serial_number' => 'ATHAR-AI101-2026-9001', 'verify_code' => str_repeat('b', 40)]);

    expect(Certificate::query()->where('user_id', $this->holder->id)->count())->toBe(2)
        ->and($second->revoked_at)->toBeNull()
        ->and($revoked->fresh()->revoked_at)->not->toBeNull();
});

/**
 * Another request writes a live certificate for the same person AFTER this one has looked and
 * found none — the window the unique key used to close. Fired from the first «is there a live
 * one» query this request makes, so the second writer lands exactly between look and write.
 *
 * @param  Closure(): void  $concurrent
 */
function raceOnLiveLookup(Closure $concurrent): void
{
    $fired = false;

    DB::listen(static function ($query) use (&$fired, $concurrent): void {
        if ($fired) {
            return;
        }

        $sql = strtolower($query->sql);

        if (str_contains($sql, 'from "certificates"') && str_contains($sql, '"revoked_at" is null') && str_contains($sql, 'exists')) {
            $fired = true;
            $concurrent();
        }
    });
}

it('BR-26: إصدارٌ يسبقه إصدارٌ آخر لنفس الشخص بين الفحص والكتابة يُرفض — لا شهادتان ساريتان', function (): void {
    $other = null;

    raceOnLiveLookup(function () use (&$other): void {
        $other = issueCertificateFor($this->holder, $this->cohort, ['serial_number' => 'ATHAR-AI101-2026-9002', 'verify_code' => str_repeat('c', 40)]);
    });

    $this->actingAs($this->admin)->post(route('admin.certificates.override', $this->holder), [
        'cohort_id' => $this->cohort->id,
        'override_reason' => 'The cohort director approved this in writing.',
    ])->assertSessionHasErrors('cohort_id');

    expect($other)->not->toBeNull()
        ->and(Certificate::query()->whereNull('revoked_at')->count())->toBe(1)
        ->and(Certificate::query()->whereNull('revoked_at')->sole()->id)->toBe($other->id)
        ->and(AuditLog::query()->whereIn('action', ['certificate.issued', 'certificate.overridden'])->count())->toBe(0);
});

it('BR-26: إعادة الإصدار التي يسبقها إصدارٌ آخر بين الفحص والكتابة تتراجع كلها — تبقى الأولى سارية', function (): void {
    $original = issueCertificateFor($this->holder, $this->cohort, ['revoked_at' => null]);

    raceOnLiveLookup(function (): void {
        issueCertificateFor($this->holder, $this->cohort, ['serial_number' => 'ATHAR-AI101-2026-9003', 'verify_code' => str_repeat('d', 40)]);
    });

    $this->actingAs($this->admin)->post(route('admin.certificates.reissue', $original))
        ->assertSessionHasErrors('certificate');

    // The original was not revoked on the way to a replacement that could not be written.
    expect($original->fresh()->revoked_at)->toBeNull()
        ->and(Certificate::query()->whereNull('revoked_at')->count())->toBe(2)
        ->and(AuditLog::query()->whereIn('action', ['certificate.revoked', 'certificate.issued'])->count())->toBe(0);
});
