<?php

declare(strict_types=1);

/**
 * BR-27, BR-29, BR-30: the audit trail is append-only, a password change ends every
 * other session, and neither login nor password reset ever reveals whether an email
 * address exists.
 *
 * Route names are the ones routes/web.php actually declares, not assumed ones:
 * the participant changes their own password with PUT `profile.password`
 * (/dashboard/profile/password), and a trainer records a grade with POST
 * `trainer.submissions.grade` against one submission in the URL.
 *
 * @see BR-27, BR-29, BR-30 · PRD §9.3, §12.1, §12.3 · CONSTITUTION.md Articles 8, 24
 */

use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->participant = withPassword(
        makeParticipant($this->cohort, ['email' => 'existing.trainee@example.test']),
        'Correct-Horse-9'
    );
});

/*
|--------------------------------------------------------------------------
| BR-27 — the audit log is written, and can never be rewritten
|--------------------------------------------------------------------------
*/

it('BR-27: كل عملية حساسة تُسجَّل في سجل التدقيق', function () {
    $trainer = makeTrainer($this->cohort);
    $assignment = makeAssignment($this->cohort, ['max_score' => 10]);
    $submission = makeSubmission($assignment, $this->participant);

    $this->actingAs($trainer)->post(route('trainer.submissions.grade', $submission), [
        'score' => 8,
        'feedback' => 'A solid submission with a clear evaluation section.',
    ]);

    $log = AuditLog::query()->where('entity_type', 'evaluation')->sole();

    expect($log->actor_id)->toBe($trainer->id)
        ->and($log->action)->not->toBeNull()
        ->and($log->ip_address)->not->toBeNull()
        ->and($log->user_agent)->not->toBeNull()
        ->and($log->created_at)->not->toBeNull();
});

it('BR-27, BR-28: رفض السياسة نفسه يُسجَّل في سجل التدقيق مع عنوان IP', function () {
    // PRD §4.3: «أي محاولة وصول غير مصرح بها تُرجع خطأ 403 وتُسجَّل في سجل التدقيق
    // مع عنوان IP» — 403 AND a row, not one or the other.
    //
    // The middleware gates (EnsureRole, EnsureCohortScope, ImpersonationReadOnly)
    // record their own refusals through App\Http\Middleware\Concerns\LogsDenials.
    // A POLICY saying no inside a controller or a FormRequest is the other door,
    // and it is the one this asserts: bootstrap/app.php turns the resulting
    // AuthorizationException into an audit row before the 403 page is rendered.
    // Nothing else in the suite watches that door.
    $otherCohort = makeCohort();
    $foreignAssignment = makeAssignment($otherCohort, ['max_score' => 10]);

    $this->actingAs($this->participant)
        ->get(route('assignments.show', $foreignAssignment))
        ->assertForbidden();

    $log = AuditLog::query()->where('action', 'access.denied')->sole();

    expect($log->actor_id)->toBe($this->participant->id)
        ->and($log->ip_address)->not->toBeNull()
        ->and($log->after['reason'] ?? null)->toBe('policy.denied')
        ->and($log->after['route'] ?? null)->toBe('assignments.show')
        ->and($log->entity_id)->toBe($foreignAssignment->id);
});

it('BR-27: سجل التدقيق غير قابل للتعديل من طبقة النموذج', function () {
    $log = AuditLog::factory()->create([
        'actor_id' => $this->participant->id,
        'action' => 'test.action',
        'entity_type' => 'user',
        'entity_id' => $this->participant->id,
    ]);

    expect(method_exists($log, 'update'))->toBeTrue();

    // The model must refuse, not quietly obey. Whatever exception the guard raises,
    // the stored row must be identical afterwards.
    try {
        $log->update(['action' => 'tampered.action']);
    } catch (Throwable) {
        // Expected.
    }

    expect(AuditLog::query()->find($log->id)->action)->toBe('test.action');
});

it('BR-27: سجل التدقيق غير قابل للحذف من طبقة النموذج', function () {
    $log = AuditLog::factory()->create([
        'actor_id' => $this->participant->id,
        'action' => 'test.action',
        'entity_type' => 'user',
        'entity_id' => $this->participant->id,
    ]);

    try {
        $log->delete();
    } catch (Throwable) {
        // Expected.
    }

    expect(AuditLog::query()->count())->toBe(1);
});

it('BR-27: لا يوجد أي مسار في التطبيق يعدّل سجل التدقيق أو يحذفه', function () {
    $writable = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => (bool) array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']))
        ->map(fn ($route): string => (string) $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'audit'))
        ->values();

    expect($writable)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| BR-29 — a password change ends every other session
|--------------------------------------------------------------------------
*/

it('BR-29: تغيير كلمة المرور يُبطل كل الجلسات النشطة الأخرى', function () {
    // phpunit.xml runs the suite on the `array` session driver, and
    // InvalidatesOtherSessions deliberately does nothing unless the driver is
    // `database` — there are no rows to delete otherwise. This one rule is about the
    // rows, so it turns the production driver on for itself; without this the
    // assertion below would pass while observing nothing at all.
    //
    // The table is `user_sessions`. `sessions` is the TRAINING sessions table of
    // PRD §7.3 and inserting a framework session row into it fails outright.
    config([
        'session.driver' => 'database',
        'session.table' => 'user_sessions',
    ]);

    $otherSessionId = (string) Str::uuid();

    DB::table('user_sessions')->insert([
        'id' => $otherSessionId,
        'user_id' => $this->participant->id,
        'ip_address' => '203.0.113.7',
        'user_agent' => 'AnotherDevice/1.0',
        'payload' => base64_encode(serialize([])),
        'last_activity' => riyadhAt('2026-10-12 11:00:00')->getTimestamp(),
    ]);

    assertAccepted($this->actingAs($this->participant)->put(route('profile.password'), [
        'current_password' => 'Correct-Horse-9',
        'password' => 'Battery-Staple-12',
        'password_confirmation' => 'Battery-Staple-12',
    ]));

    expect(DB::table('user_sessions')->where('id', $otherSessionId)->count())->toBe(0);
});

it('BR-29: كلمة المرور الجديدة تحل محل القديمة ولا تبقى القديمة صالحة', function () {
    $before = $this->participant->fresh()->getAuthPassword();

    $this->actingAs($this->participant)->put(route('profile.password'), [
        'current_password' => 'Correct-Horse-9',
        'password' => 'Battery-Staple-12',
        'password_confirmation' => 'Battery-Staple-12',
    ]);

    $after = $this->participant->fresh()->getAuthPassword();

    expect($after)->not->toBe($before)
        ->and(Hash::check('Battery-Staple-12', $after))->toBeTrue()
        ->and(Hash::check('Correct-Horse-9', $after))->toBeFalse();

    // Sign out first, or there is nothing to prove: PRD §9.3.3 keeps the
    // session that performed the change alive (only the OTHER sessions die),
    // so a signed-in caller reaching POST /login is turned away by the `guest`
    // middleware and redirected to the dashboard — no login attempt is ever
    // made, and the old password is never actually offered to the server.
    $this->post(route('logout'));
    $this->assertGuest();

    $this->post(route('login'), [
        'email' => 'existing.trainee@example.test',
        'password' => 'Correct-Horse-9',
    ])->assertSessionHasErrors();

    $this->assertGuest();
});

it('BR-29: تغيير كلمة المرور بكلمة مرور حالية خاطئة مرفوض', function () {
    $before = $this->participant->fresh()->getAuthPassword();

    assertRefused($this->actingAs($this->participant)->put(route('profile.password'), [
        'current_password' => 'Not-The-Current-One',
        'password' => 'Battery-Staple-12',
        'password_confirmation' => 'Battery-Staple-12',
    ]));

    expect($this->participant->fresh()->getAuthPassword())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| BR-30 — the two responses are indistinguishable
|--------------------------------------------------------------------------
*/

it('BR-30: رسالة الدخول واحدة سواء كان البريد موجودًا أو غير موجود', function () {
    $attempt = function (string $email): array {
        $this->flushSession();

        $response = $this->post(route('login'), [
            'email' => $email,
            'password' => 'Wrong-Password-1',
        ]);

        return [
            'status' => $response->status(),
            'errors' => session('errors') ? session('errors')->getBag('default')->all() : [],
            'body' => $response->getContent(),
        ];
    };

    $existing = $attempt('existing.trainee@example.test');
    $missing = $attempt('no.such.person@example.test');

    expect($missing['status'])->toBe($existing['status'])
        ->and($missing['errors'])->toBe($existing['errors'])
        ->and($missing['body'])->toBe($existing['body']);

    expect(json_encode($existing['errors']))->not->toContain('existing.trainee@example.test');

    $this->assertGuest();
});

it('BR-30: رسالة استعادة كلمة المرور واحدة سواء كان البريد موجودًا أو غير موجود', function () {
    $request = function (string $email): array {
        $this->flushSession();

        $response = $this->post(route('password.request'), ['email' => $email]);

        return [
            'status' => $response->status(),
            'flash' => session('status'),
            'errors' => session('errors') ? session('errors')->getBag('default')->all() : [],
        ];
    };

    $existing = $request('existing.trainee@example.test');
    $missing = $request('no.such.person@example.test');

    expect($missing['status'])->toBe($existing['status'])
        ->and($missing['flash'])->toBe($existing['flash'])
        ->and($missing['errors'])->toBe($existing['errors']);
});

it('BR-30: صفحة استعادة كلمة المرور تُخرج النص نفسه في الحالتين', function () {
    $this->flushSession();
    $existing = $this->post(route('password.request'), ['email' => 'existing.trainee@example.test'])
        ->getContent();

    $this->flushSession();
    $missing = $this->post(route('password.request'), ['email' => 'no.such.person@example.test'])
        ->getContent();

    expect($missing)->toBe($existing);
});

it('BR-30: قاعدة البيانات ترفض تكرار البريد فلا يمكن اكتشافه بمحاولة التسجيل', function () {
    // PRD §7.7 requires the unique index to exist in the schema, not only in a rule.
    expect(fn () => makeParticipant($this->cohort, ['email' => 'existing.trainee@example.test']))
        ->toThrow(QueryException::class);
});
