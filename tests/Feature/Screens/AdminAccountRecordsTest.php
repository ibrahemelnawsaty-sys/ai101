<?php

declare(strict_types=1);

/**
 * Three things the administrator could not do, or could do and should not.
 *
 * WHY THIS SUITE EXISTS
 *   · An account created without a cohort had no way into one from the
 *     interface; its own empty state said so (D-69).
 *   · A deleted account's address passed validation and failed the insert: a
 *     500 on "add a user", on the import, and on public registration, where
 *     the unique index covers deleted rows and the rule did not (D-69).
 *   · PRD §7.8 — "no user with evaluation records or an issued certificate is
 *     deleted" — was asked by nothing (D-84).
 *
 * @see PRD §4.2, §7.8, §9.18 · BR-23 · FR-CERT-13 · D-69, D-84
 */

use App\Models\Cohort;
use App\Models\DigitalCard;
use App\Models\Enrollment;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));
    Mail::fake();

    $this->admin = makeAdmin();
    $this->cohort = makeCohort(['status' => 'open']);
    makeTrainer($this->cohort);
});

/*
|--------------------------------------------------------------------------
| Seating an existing account
|--------------------------------------------------------------------------
*/

it('D-84: المدير يُجلس حسابًا قائمًا في دفعة — التحاق نشط وبطاقة ومحادثات وسطر تدقيق، ولا رسالة', function (): void {
    $orphan = makeParticipant();
    $seatsBefore = (int) $this->cohort->seats_taken;

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', $orphan))
        ->assertOk()
        ->assertSee(route('admin.users.enroll', $orphan), false)
        ->assertSee($this->cohort->name, false);

    $this->actingAs($this->admin)
        ->post(route('admin.users.enroll', $orphan), ['cohort_id' => $this->cohort->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('admin.users.enrolled'));

    $enrollment = Enrollment::query()->where('user_id', $orphan->id)->sole();
    $threads = Thread::query()->whereIn('id', ThreadParticipant::query()->where('user_id', $orphan->id)->select('thread_id'))->pluck('type')
        ->map(static fn (mixed $t): string => $t instanceof BackedEnum ? (string) $t->value : (string) $t)->sort()->values()->all();

    expect($enrollment->cohort_id)->toBe($this->cohort->id)
        ->and($enrollment->status->value ?? $enrollment->status)->toBe('active')
        ->and((int) Cohort::query()->findOrFail($this->cohort->id)->seats_taken)->toBe($seatsBefore + 1)
        ->and(DigitalCard::query()->where('user_id', $orphan->id)->count())->toBe(1)
        ->and($threads)->toBe(['announcement', 'group', 'trainer_dm'])
        ->and(App\Models\AuditLog::query()->where('action', 'enrollment.seated')->where('entity_id', $orphan->id)->count())->toBe(1);

    Mail::assertNothingQueued();
});

it('D-84: مرّة ثانية تُرفض برسالة، ولا تُعرض دفعة منتهية ولا دفعة التحق بها', function (): void {
    $participant = makeParticipant($this->cohort);
    $finished = makeCohort(['status' => 'completed', 'name' => 'CANARY-FINISHED']);

    $this->actingAs($this->admin)
        ->post(route('admin.users.enroll', $participant), ['cohort_id' => $this->cohort->id])
        ->assertSessionHasErrors(['cohort_id' => __('admin.users.enroll_already')]);

    $this->actingAs($this->admin)
        ->post(route('admin.users.enroll', $participant), ['cohort_id' => $finished->id])
        ->assertSessionHasErrors('cohort_id');

    expect(Enrollment::query()->where('user_id', $participant->id)->count())->toBe(1);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', $participant))
        ->assertOk()
        ->assertDontSee('CANARY-FINISHED', false)
        ->assertSee(__('admin.users.enroll_none'), false);
});

it('D-84: إجلاس ثانٍ لحساب في دفعته يعود false ولا يُكرّر شيئًا — جواب القفل لا ما قبله', function (): void {
    $orphan = makeParticipant();
    $inviter = app(App\Services\Credentials\AccountInviter::class);

    expect($inviter->enrollExisting($orphan, $this->cohort))->toBeTrue()
        ->and($inviter->enrollExisting($orphan, $this->cohort))->toBeFalse()
        ->and(Enrollment::query()->where('user_id', $orphan->id)->count())->toBe(1)
        ->and(DigitalCard::query()->where('user_id', $orphan->id)->count())->toBe(1)
        ->and(App\Models\AuditLog::query()->where('action', 'enrollment.seated')->where('entity_id', $orphan->id)->count())->toBe(1);
});

it('D-84: لا يُجلَس إلا حساب متدرّب، ولا يُجلِس إلا المدير', function (): void {
    $trainer = makeTrainer();
    $orphan = makeParticipant();

    $this->actingAs($this->admin)
        ->post(route('admin.users.enroll', $trainer), ['cohort_id' => $this->cohort->id])
        ->assertForbidden();

    $this->actingAs(makeTrainer($this->cohort))
        ->post(route('admin.users.enroll', $orphan), ['cohort_id' => $this->cohort->id])
        ->assertForbidden();

    expect(Enrollment::query()->where('user_id', $orphan->id)->exists())->toBeFalse()
        ->and(Enrollment::query()->where('user_id', $trainer->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| A deleted account's address
|--------------------------------------------------------------------------
*/

it('D-84: عنوان حساب محذوف يُرفض برسالة لا بخطأ خادم — في الإضافة والتسجيل والاستيراد', function (): void {
    $gone = makeParticipant(null, ['email' => 'gone@example.com']);
    $gone->delete();

    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), [
            'email' => 'gone@example.com',
            'role' => 'participant',
            'status' => 'active',
            'cohort_id' => $this->cohort->id,
        ])
        ->assertSessionHasErrors('email');

    auth()->logout();

    $this->post(route('register'), ['email' => 'gone@example.com', 'email_confirmation' => 'gone@example.com'])
        ->assertSessionHasErrors('email');

    // The import's own reader, on a real row, and its queued job.
    $rows = app(App\Services\Import\ParticipantImportReader::class)
        ->read(importFileWith('gone@example.com'), 'csv');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->errors)->toContain((string) __('admin.users.import.errors.email_taken'));

    $before = User::withTrashed()->count();

    (new App\Jobs\InviteImportedParticipant(
        'gone@example.com',
        ['first_name_ar' => 'CANARY', 'second_name_ar' => 'A', 'third_name_ar' => 'B', 'last_name_ar' => 'C'],
        (string) $this->cohort->getKey(),
    ))->handle(app(App\Services\Credentials\AccountInviter::class));

    expect(User::withTrashed()->count())->toBe($before)
        ->and(User::withTrashed()->where('email', 'gone@example.com')->count())->toBe(1);
});

/** A one-row CSV in the import's own column order. */
function importFileWith(string $email): string
{
    $path = tempnam(sys_get_temp_dir(), 'athar').'.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, App\Services\Import\ParticipantImportSheet::headings());
    fputcsv($handle, ['CANARY', 'A', 'B', 'C', 'Canary', 'A', 'B', 'C', $email, '0512345671', 'male']);
    fclose($handle);

    return $path;
}

/*
|--------------------------------------------------------------------------
| PRD §7.8 — who may not be deleted
|--------------------------------------------------------------------------
*/

it('FR-CERT-13: لا يُحذف مستخدم له شهادة صادرة — ولو سُحبت — ولا من له تقييم؛ ويُحذف من ليس له', function (): void {
    $certified = makeParticipant($this->cohort);
    issueCertificateFor($certified, $this->cohort, ['revoked_at' => riyadhAt('2026-11-21 09:00:00')]);

    $graded = makeParticipant($this->cohort);
    $assignment = makeAssignment($this->cohort);
    makeEvaluation('assignment', makeSubmission($assignment, $graded)->id, $graded, 7.0);

    $clean = makeParticipant($this->cohort);

    foreach ([$certified, $graded] as $subject) {
        $this->actingAs($this->admin)
            ->delete(route('admin.users.destroy', $subject), ['reason' => 'Duplicate account created by mistake.'])
            ->assertForbidden();

        expect(User::query()->whereKey($subject->id)->exists())->toBeTrue();
    }

    $this->actingAs($this->admin)
        ->delete(route('admin.users.destroy', $clean), ['reason' => 'Duplicate account created by mistake.'])
        ->assertRedirect(route('admin.users.index'));

    expect(User::query()->whereKey($clean->id)->exists())->toBeFalse()
        ->and(User::withTrashed()->whereKey($clean->id)->exists())->toBeTrue();
});
