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
 * D-117 moved the first of the three to the cohorts screen and the general
 * supervisor, and left the other two with the system administrator.
 *
 * @see PRD §4.2, §7.8, §9.18 · BR-23 · FR-CERT-13 · D-69, D-84, D-117
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

    // D-117: seating is the general supervisor's, from the cohorts screen; the
    // accounts themselves are the system administrator's.
    $this->admin = makeAdmin();
    $this->sysadmin = makeSystemAdmin();
    $this->cohort = makeCohort(['status' => 'open']);
    makeTrainer($this->cohort);
});

/*
|--------------------------------------------------------------------------
| Seating an existing account — the cohorts screen (D-84, moved by D-117)
|--------------------------------------------------------------------------
*/

it('D-84: المشرف يُجلس حسابًا قائمًا في دفعة من شاشة الدفعات — التحاق نشط وبطاقة ومحادثات وسطر تدقيق، ولا رسالة', function (): void {
    $orphan = makeParticipant(null, ['email' => 'orphan@example.com']);
    $seatsBefore = (int) $this->cohort->seats_taken;

    $this->actingAs($this->admin)
        ->get(route('admin.cohorts.index', ['participants' => $this->cohort->id]))
        ->assertOk()
        ->assertSee(route('admin.cohorts.participants.attach', $this->cohort), false)
        ->assertSee(__('admin.cohorts.participant_email'), false);

    $this->actingAs($this->admin)
        ->post(route('admin.cohorts.participants.attach', $this->cohort), ['email' => ' Orphan@Example.com '])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('admin.cohorts.participant_seated'));

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

it('D-84: مرّة ثانية تُرفض برسالة، ولا يُجلَس أحد في دفعة منتهية', function (): void {
    $participant = makeParticipant($this->cohort, ['email' => 'seated@example.com']);
    $finished = makeCohort(['status' => 'completed']);

    $this->actingAs($this->admin)
        ->post(route('admin.cohorts.participants.attach', $this->cohort), ['email' => 'seated@example.com'])
        ->assertSessionHasErrors(['email' => __('admin.cohorts.participant_already')]);

    $this->actingAs($this->admin)
        ->post(route('admin.cohorts.participants.attach', $finished), ['email' => 'seated@example.com'])
        ->assertSessionHasErrors(['email' => __('admin.cohorts.seat_closed')]);

    expect(Enrollment::query()->where('user_id', $participant->id)->count())->toBe(1);
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

it('D-117: لا يُجلَس إلا حساب متدرّب، ولا يُجلِس إلا المشرف العام — لا مدير النظام ولا المدرب', function (): void {
    $trainer = makeTrainer(null, ['email' => 'lone-trainer@example.com']);
    $orphan = makeParticipant(null, ['email' => 'orphan@example.com']);

    // A trainer's address is not a participant's: refused as unknown, not seated.
    $this->actingAs($this->admin)
        ->post(route('admin.cohorts.participants.attach', $this->cohort), ['email' => 'lone-trainer@example.com'])
        ->assertSessionHasErrors(['email' => __('admin.cohorts.participant_not_found')]);

    foreach ([$this->sysadmin, makeTrainer($this->cohort)] as $actor) {
        $this->actingAs($actor)
            ->post(route('admin.cohorts.participants.attach', $this->cohort), ['email' => 'orphan@example.com'])
            ->assertForbidden();
    }

    expect(Enrollment::query()->where('user_id', $orphan->id)->exists())->toBeFalse()
        ->and(Enrollment::query()->where('user_id', $trainer->id)->exists())->toBeFalse();
});

it('D-117: صفحة الحساب عند مدير النظام بلا سجل تدقيق وبلا زر إجلاس، وتسمّي من يُجلس', function (): void {
    $orphan = makeParticipant();
    // A trail row about this account, written the way every row is, from a
    // request whose address the page must not show.
    request()->server->set('REMOTE_ADDR', '203.0.113.77');
    app(App\Services\Audit\AuditLogger::class)->record(
        action: 'user.updated',
        entityType: 'user',
        entityId: (string) $orphan->id,
        actorId: (string) $this->admin->id,
    );
    expect(App\Models\AuditLog::query()->where('entity_id', $orphan->id)->value('ip_address'))->toBe('203.0.113.77');

    $this->actingAs($this->sysadmin)
        ->get(route('admin.users.show', $orphan))
        ->assertOk()
        ->assertDontSee('203.0.113.77', false)
        // The card's own closing line; its title also names the trail in a
        // form hint, so the title alone cannot tell the card is gone.
        ->assertDontSee(__('admin.audit.immutable_note'), false)
        ->assertDontSee(route('admin.audit.index'), false)
        ->assertDontSee(route('admin.cohorts.index'), false)
        ->assertSee(__('admin.users.enrollments_empty_participant_body'), false);
});

/*
|--------------------------------------------------------------------------
| A deleted account's address
|--------------------------------------------------------------------------
*/

it('D-84: عنوان حساب محذوف يُرفض برسالة لا بخطأ خادم — في الإضافة والتسجيل والاستيراد', function (): void {
    $gone = makeParticipant(null, ['email' => 'gone@example.com']);
    $gone->delete();

    $this->actingAs($this->sysadmin)
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
    fputcsv($handle, ['CANARY', 'A', 'B', 'C', $email, '0512345671']);
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
        $this->actingAs($this->sysadmin)
            ->delete(route('admin.users.destroy', $subject), ['reason' => 'Duplicate account created by mistake.'])
            ->assertForbidden();

        expect(User::query()->whereKey($subject->id)->exists())->toBeTrue();
    }

    $this->actingAs($this->sysadmin)
        ->delete(route('admin.users.destroy', $clean), ['reason' => 'Duplicate account created by mistake.'])
        ->assertRedirect(route('admin.users.index'));

    expect(User::query()->whereKey($clean->id)->exists())->toBeFalse()
        ->and(User::withTrashed()->whereKey($clean->id)->exists())->toBeTrue();
});
