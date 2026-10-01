<?php

declare(strict_types=1);

/**
 * An account that was handed a temporary password: forced to choose its own, let in.
 *
 * WHY THIS SUITE STILL EXISTS (D-152)
 * Nothing makes such an account any more — the owner decided the bulk import invites by a
 * single-use link like every other invitation, and the code that generated and mailed a
 * temporary password is gone (ImportInvitesByLinkTest holds that). But accounts made by the old
 * path are in the table: verified, `must_change_password`, a window to use the password in. The
 * gate they meet is still in force, and the cases below hold the parts that are silent when it
 * breaks: a flag that is set but never cleared, a middleware that must not trap the screen it
 * redirects to, an expiry that must be refused where recovery is still reachable, and a first
 * change that must not mail a «your password changed» warning about itself.
 *
 * The accounts here are built in that old state directly: `legacyTemporaryAccount()`.
 *
 * @see PRD §4.5.1, §9.2, §9.3.3 · BR-29, BR-30, BR-34 · D-63, D-69, D-152
 */

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\Profile;
use App\Models\User;
use App\Services\Credentials\AccountInviter;
use App\Services\Credentials\TemporaryPassword;
use App\Services\Time\Clock;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 09:00:00'));

    $this->cohort = makeCohort();
    // D-117 — invitations are the system administrator's.
    $this->admin = makeSystemAdmin();
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

/**
 * An account in the state the old temporary-password path left it in: verified, in its cohort,
 * told to choose a password, holding the temporary one it was given.
 *
 * @return array{0: User, 1: string} the account and the temporary password
 */
function legacyTemporaryAccount(object $test, string $email = 'canary@example.com'): array
{
    $temporary = app(TemporaryPassword::class)->generate();

    $user = User::factory()->create([
        'email' => $email,
        'role' => UserRole::Participant->value,
        'status' => 'active',
        'email_verified_at' => Clock::now(),
        'must_change_password' => true,
        'temp_password_expires_at' => Clock::now()->addDays((int) config('athar.invitations.temp_password_days', 7)),
        'invited_at' => Clock::now(),
    ]);

    withPassword($user, $temporary);

    Profile::query()->create(array_merge(inviteProfileColumns(), ['user_id' => $user->getKey()]));
    enroll($user, $test->cohort, 'participant');

    return [$user->fresh(), $temporary];
}

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

    [$user] = legacyTemporaryAccount($this);

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
    [$user, $temporary] = legacyTemporaryAccount($this, 'canary@example.com');

    $this->actingAs($user)
        ->put(route('password.first.update'), [
            'current_password' => $temporary,
            'password' => 'Canary-Sets-This-1!',
            'password_confirmation' => 'Canary-Sets-This-1!',
        ])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(App\Http\Controllers\Participant\DashboardController::WELCOME_KEY, true);

    $user->refresh();

    // The exact mirror of what AccountInviter set. A flag that is set and never
    // cleared redirects the account back to this screen for ever.
    expect((bool) $user->getAttribute('must_change_password'))->toBeFalse()
        ->and($user->getAttribute('temp_password_expires_at'))->toBeNull();

    // The celebration shows on the first render, is removed by it, and never
    // shows again (D-75: a session value removed after the render, not a flash).
    $key = App\Http\Controllers\Participant\DashboardController::WELCOME_KEY;

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('wel__ttl', false)
        ->assertViewHas('celebrate', true)
        ->assertSessionMissing($key);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('wel__ttl', false)
        ->assertViewHas('celebrate', false);
});

it('D-75: عرضٌ فاشل للّوحة يُبقي الترحيب للزيارة التالية', function (): void {
    Mail::fake();
    $key = App\Http\Controllers\Participant\DashboardController::WELCOME_KEY;
    [$user, $temporary] = legacyTemporaryAccount($this, 'welcome@example.com');

    $this->actingAs($user)->put(route('password.first.update'), [
        'current_password' => $temporary,
        'password' => 'Canary-Sets-This-1!',
        'password_confirmation' => 'Canary-Sets-This-1!',
    ]);

    // The first render throws — a template, a presenter, the layout.
    $fail = true;
    Illuminate\Support\Facades\View::composer('participant.dashboard', function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('render');
        }
    });

    $this->actingAs($user)->get(route('dashboard'))->assertStatus(500)->assertSessionHas($key, true);

    $fail = false;
    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('wel__ttl', false)->assertSessionMissing($key);
    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('wel__ttl', false);
});

it('D-75: المدرّب المدعوّ لا يبقى مفتاح الترحيب في جلسته', function (): void {
    $key = App\Http\Controllers\Participant\DashboardController::WELCOME_KEY;
    $trainer = makeTrainer($this->cohort);

    $this->actingAs($trainer)->withSession([$key => true])
        ->get(route('dashboard'))
        // The trainer's own dashboard (PR-5) is where /dashboard sends them; the
        // welcome key is what this test is about, not which trainer screen is home.
        ->assertRedirect(route('trainer.dashboard'))
        ->assertSessionMissing($key);
});

it('D-63: التغيير الأول لا يُرسل تحذير «غُيّرت كلمة مرورك»', function (): void {
    Mail::fake();
    [$user, $temporary] = legacyTemporaryAccount($this, 'canary@example.com');

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
    [$user, $temporary] = legacyTemporaryAccount($this, 'canary@example.com');

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

it('BR-29: التغيير الأول ينهي كل جلسة أخرى لهذا الحساب', function (): void {
    Mail::fake();
    [$user, $temporary] = legacyTemporaryAccount($this, 'canary@example.com');

    // A second live session for the same account. This is not a contrived case:
    // the invitation carries a plaintext password to an inbox that gets
    // forwarded, and the mundane version needs no intruder at all — the trainee
    // opens the letter on a phone and again on a laptop.
    //
    // phpunit.xml runs the suite on the `array` driver, where
    // InvalidatesOtherSessions has no rows to delete and rightly does nothing.
    // This case is ABOUT the rows, so it turns the production driver on for
    // itself — as SecurityRulesTest's BR-29 case does. Without it the assertion
    // below fails for the wrong reason, or would pass observing nothing.
    config([
        'session.driver' => 'database',
        'session.table' => 'user_sessions',
    ]);
    $table = 'user_sessions';

    Illuminate\Support\Facades\DB::table($table)->insert([
        'id' => 'CANARY-OTHER-SESSION',
        'user_id' => $user->getKey(),
        'ip_address' => '203.0.113.9',
        'user_agent' => 'canary',
        'payload' => base64_encode(serialize([])),
        'last_activity' => Clock::now()->getTimestamp(),
    ]);

    $user->forceFill(['remember_token' => 'CANARY-REMEMBER'])->save();

    $this->actingAs($user)->put(route('password.first.update'), [
        'current_password' => $temporary,
        'password' => 'Canary-Sets-This-1!',
        'password_confirmation' => 'Canary-Sets-This-1!',
    ])->assertRedirect(route('dashboard'));

    // Until this moment the other session was trapped on the change screen by
    // RequirePasswordChange and could see nothing. Clearing the flag frees the
    // ACCOUNT, so a row left behind is promoted to a full trainee session.
    expect(Illuminate\Support\Facades\DB::table($table)->where('id', 'CANARY-OTHER-SESSION')->count())->toBe(0);

    // And a "remember me" cookie taken at that sign-in must die with it.
    expect($user->fresh()?->getAttribute('remember_token'))->toBeNull();
});

it('D-69: دعوتان إلى دفعة واحدة تأخذ كلٌّ منهما مقعدًا واحدًا بالضبط', function (): void {
    Mail::fake();
    $before = (int) $this->cohort->fresh()?->seats_taken;

    // Two instances loaded BEFORE either invite, as two administrators' requests
    // would hold them. Seating used to write "the count I read + 1" from the
    // instance it was handed, under a lock already released — so the second
    // invite overwrote the first one's seat.
    $first = App\Models\Cohort::query()->findOrFail($this->cohort->getKey());
    $second = App\Models\Cohort::query()->findOrFail($this->cohort->getKey());

    app(AccountInviter::class)->inviteByLink(
        email: 'one@example.com',
        role: UserRole::Participant,
        profileColumns: array_merge(inviteProfileColumns(), ['phone' => '0512345671']),
        cohort: $first,
    );
    app(AccountInviter::class)->inviteByLink(
        email: 'two@example.com',
        role: UserRole::Participant,
        profileColumns: array_merge(inviteProfileColumns(), ['phone' => '0512345672']),
        cohort: $second,
    );

    expect((int) $this->cohort->fresh()?->seats_taken)->toBe($before + 2)
        ->and(Enrollment::query()->where('cohort_id', $this->cohort->getKey())->participants()->count())->toBe(2);
});
