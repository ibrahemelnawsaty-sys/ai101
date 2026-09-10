<?php

declare(strict_types=1);

/**
 * An account created FOR someone: invited, forced to choose a password, let in.
 *
 * WHY THIS SUITE EXISTS
 * Registration for the first cohort closed and there was no other way in. The
 * admin panel's "add a user" button rendered the list again — `create()` passed
 * `'creating' => true` to a template that never read it — and even when
 * `store()` was reached it produced an account in NO cohort, with no letter, and
 * with `email_verified_at` already stamped so `resendVerification()` refused to
 * help. Sixty trainees had no route to the platform (D-63).
 *
 * The cases below hold the parts that are silent when they break: a flag that
 * is set but never cleared, a letter that carries a credential, a middleware
 * that must not trap the screen it redirects to, and an expiry that must be
 * refused where recovery is still reachable.
 *
 * @see PRD §4.5.1, §9.2, §9.3.3 · BR-29, BR-30, BR-34 · D-63
 */

use App\Enums\UserRole;
use App\Mail\InvitationLetter;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Credentials\AccountInviter;
use App\Services\Credentials\TemporaryPassword;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 09:00:00'));

    $this->cohort = makeCohort();
    $this->admin = makeAdmin();
});

/** The eleven facts the form and the import both collect. */
function inviteProfileColumns(): array
{
    return [
        'first_name_ar' => 'CANARY-FIRST',
        'second_name_ar' => 'CANARY-SECOND',
        'third_name_ar' => 'CANARY-THIRD',
        'last_name_ar' => 'CANARY-LAST',
        'first_name_en' => 'Canary',
        'second_name_en' => 'Second',
        'third_name_en' => 'Third',
        'last_name_en' => 'Last',
        'phone' => '0512345678',
        'gender' => 'male',
    ];
}

it('D-63: الدعوة تُنشئ الحساب وتُلحقه بالدفعة وتُطابر رسالته', function (): void {
    Mail::fake();

    $user = app(AccountInviter::class)->invite(
        email: 'canary@example.com',
        role: UserRole::Participant,
        profileColumns: inviteProfileColumns(),
        cohort: $this->cohort,
    );

    expect((bool) $user->getAttribute('must_change_password'))->toBeTrue()
        ->and($user->getAttribute('temp_password_expires_at'))->not->toBeNull()
        ->and($user->getAttribute('invited_at'))->not->toBeNull()
        // Verified on creation: LoginController refuses an unverified account
        // and resendVerification() refuses a verified one, so an invited
        // account that arrived unverified could neither sign in nor be helped.
        ->and($user->getAttribute('email_verified_at'))->not->toBeNull();

    // The seat is the whole point: without it the account has no assignments,
    // no sessions, no card and no certificate path.
    expect(Enrollment::query()
        ->where('user_id', $user->getKey())
        ->where('cohort_id', $this->cohort->getKey())
        ->count())->toBe(1);

    Mail::assertQueued(InvitationLetter::class, fn (InvitationLetter $letter): bool => $letter->email === 'canary@example.com');
});

it('BR-30: كلمة المرور المؤقتة لا تصل إلى سجلّ التدقيق إطلاقًا', function (): void {
    Mail::fake();

    $user = app(AccountInviter::class)->invite(
        email: 'canary@example.com',
        role: UserRole::Participant,
        profileColumns: inviteProfileColumns(),
        cohort: $this->cohort,
    );

    // The letter is the only place the plaintext exists. Whatever it carries
    // must not appear anywhere in the trail — an administrator who can read a
    // trainee's password can sign in as them with no impersonation record.
    $sent = null;
    Mail::assertQueued(InvitationLetter::class, function (InvitationLetter $letter) use (&$sent): bool {
        $sent = $letter->password;

        return true;
    });

    expect($sent)->toBeString()->and($sent)->not->toBe('');

    $trail = AuditLog::query()
        ->where('entity_id', $user->getKey())
        ->get()
        ->map(static fn (AuditLog $row): string => json_encode($row->getAttributes(), JSON_UNESCAPED_UNICODE) ?: '')
        ->implode(' ');

    expect($trail)->not->toContain($sent)
        // And the row it did write says an invitation happened.
        ->and($trail)->toContain('user.invited');
});

it('D-63: كلمة المرور المولّدة تجتاز قواعد المنصة نفسها', function (): void {
    // A generator that produced a password the platform then rejects would fail
    // at the worst moment: when the trainee tries to replace it.
    $generator = app(TemporaryPassword::class);

    for ($i = 0; $i < 200; $i++) {
        $password = $generator->generate();

        expect(strlen($password))->toBeGreaterThanOrEqual(8)
            ->and($password)->toMatch('/[A-Z]/')
            ->and($password)->toMatch('/[a-z]/')
            ->and($password)->toMatch('/[0-9]/')
            ->and($password)->toMatch('/[^A-Za-z0-9]/')
            // Typed by hand off a phone screen: no glyph that reads as another.
            ->and($password)->not->toMatch('/[IlO01o]/');
    }
});

it('المادة 5: الحساب المدعوّ لا يصل إلى أي شاشة قبل تغيير كلمة مروره', function (): void {
    Mail::fake();

    $user = app(AccountInviter::class)->invite(
        email: 'canary@example.com',
        role: UserRole::Participant,
        profileColumns: inviteProfileColumns(),
        cohort: $this->cohort,
    );

    // Not one route, and not a redirect after sign-in: the back button, a typed
    // URL and yesterday's open tab all walk past those.
    foreach (['dashboard', 'profile', 'schedule'] as $name) {
        $this->actingAs($user)
            ->get(route($name))
            ->assertRedirect(route('password.first'));
    }

    // …and the screen it redirects to must not redirect to itself.
    $this->actingAs($user)->get(route('password.first'))->assertOk();

    // Leaving must always be possible: this page is reachable from a mailed link.
    $this->actingAs($user)->post(route('logout'))->assertRedirect();
});

it('D-63: تعيين كلمة المرور الأولى يمسح العَلَم والصلاحية ويحتفل مرة واحدة', function (): void {
    Mail::fake();

    $user = app(AccountInviter::class)->invite(
        email: 'canary@example.com',
        role: UserRole::Participant,
        profileColumns: inviteProfileColumns(),
        cohort: $this->cohort,
    );

    $temporary = null;
    Mail::assertQueued(InvitationLetter::class, function (InvitationLetter $letter) use (&$temporary): bool {
        $temporary = $letter->password;

        return true;
    });

    $this->actingAs($user)
        ->put(route('password.first.update'), [
            'current_password' => $temporary,
            'password' => 'Canary-Sets-This-1!',
            'password_confirmation' => 'Canary-Sets-This-1!',
        ])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('athar.welcome', true);

    $user->refresh();

    // The exact mirror of what AccountInviter set. A flag that is set and never
    // cleared redirects the account back to this screen for ever.
    expect((bool) $user->getAttribute('must_change_password'))->toBeFalse()
        ->and($user->getAttribute('temp_password_expires_at'))->toBeNull();

    // The celebration is a flash: the render that reads it spends it.
    $this->actingAs($user)->get(route('dashboard'))->assertSessionMissing('athar.welcome');
});

it('D-63: التغيير الأول لا يُرسل تحذير «غُيّرت كلمة مرورك»', function (): void {
    Mail::fake();

    $user = app(AccountInviter::class)->invite(
        email: 'canary@example.com',
        role: UserRole::Participant,
        profileColumns: inviteProfileColumns(),
        cohort: $this->cohort,
    );

    $temporary = null;
    Mail::assertQueued(InvitationLetter::class, function (InvitationLetter $letter) use (&$temporary): bool {
        $temporary = $letter->password;

        return true;
    });

    $this->actingAs($user)->put(route('password.first.update'), [
        'current_password' => $temporary,
        'password' => 'Canary-Sets-This-1!',
        'password_confirmation' => 'Canary-Sets-This-1!',
    ]);

    // `SendPasswordChangedNotice` used to mail unconditionally, so a trainee
    // who was ORDERED to change their password got a security warning about it
    // minutes later — sixty invitations, a hundred and twenty letters, through
    // one shared mailbox on day one.
    Mail::assertNotQueued(App\Mail\AtharLetter::class);
});

it('BR-30: كلمة مرور مؤقتة منتهية تُرفض عند الدخول لا بعده', function (): void {
    Mail::fake();

    $user = app(AccountInviter::class)->invite(
        email: 'canary@example.com',
        role: UserRole::Participant,
        profileColumns: inviteProfileColumns(),
        cohort: $this->cohort,
    );

    $temporary = null;
    Mail::assertQueued(InvitationLetter::class, function (InvitationLetter $letter) use (&$temporary): bool {
        $temporary = $letter->password;

        return true;
    });

    // Past the window configured in config/athar.php.
    freezeAt(riyadhAt('2026-09-20 09:00:00')->addDays(
        (int) config('athar.invitations.temp_password_days') + 1,
    ));

    // Refused HERE, while they are still a guest. Let in, the only screen they
    // could reach is the forced-change one, and password recovery lives behind
    // the `guest` middleware — so the link on that screen would bounce them to
    // the dashboard and back again, for ever.
    $this->post(route('login'), ['email' => 'canary@example.com', 'password' => $temporary])
        ->assertRedirect()
        ->assertSessionHas('auth.account_state', 'invitation_expired');

    expect(auth()->check())->toBeFalse();
});

it('المادة 17: شاشة إضافة مستخدم تُصيَّر فعلًا ولا تعيد القائمة', function (): void {
    // `create()` used to return `admin.users.index` with a flag that template
    // never read, so the button looked dead.
    $response = $this->actingAs($this->admin)->get(route('admin.users.create'));

    $response->assertOk()
        ->assertViewIs('admin.users.create')
        // The field that was missing entirely, and without which the account is
        // created outside every cohort.
        ->assertSee('name="cohort_id"', false)
        // The administrator does not choose a trainee's password.
        ->assertDontSee('name="password"', false);
});
