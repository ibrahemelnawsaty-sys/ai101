<?php

declare(strict_types=1);

/**
 * An invitation that asks for a name and an address, and a link that asks the
 * person themself for the rest.
 *
 * WHY THIS SUITE EXISTS
 * The single-account form demanded eleven facts before it would send anything —
 * four Arabic name parts, four Latin ones, the mobile number, the gender — and
 * an administrator holding a list of names and addresses could answer none of
 * them. The invitation now carries a link, and the parts that only the invited
 * person knows are collected behind it, with the address fixed (D-85).
 *
 * The cases below hold the parts that are silent when they break: an address
 * that must not be editable, a link that must die when it is used, an account
 * that must not be reachable before it is accepted, and a lapsed link that must
 * not leave its holder with no way back in.
 *
 * @see PRD §4.5.1, §9.2, §9.2.1 · BR-29, BR-30 · CONSTITUTION Art. 5 · D-85
 */

use App\Enums\EmailTokenType;
use App\Enums\UserRole;
use App\Events\EmailTokenIssued;
use App\Mail\EmailTokenLink;
use App\Models\AuditLog;
use App\Models\EmailToken;
use App\Models\Enrollment;
use App\Models\Profile;
use App\Models\User;
use App\Services\Credentials\AccountInviter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 09:00:00'));
    Mail::fake();

    $this->cohort = makeCohort();
    $this->admin = makeAdmin();
});

/** Invite through the admin form and hand back the raw token from the letter. */
function inviteAndCaptureToken(array $overrides = []): array
{
    $captured = null;

    Event::listen(function (EmailTokenIssued $event) use (&$captured): void {
        $captured = $event->plainToken;
    });

    test()->actingAs(test()->admin)
        ->post(route('admin.users.store'), array_merge([
            'first_name_ar' => 'محمد',
            'family_name_ar' => 'القحطاني',
            'email' => 'invited@example.com',
            'role' => 'participant',
            'status' => 'active',
            'cohort_id' => test()->cohort->id,
        ], $overrides))
        ->assertSessionHasNoErrors();

    // The administrator's own session ends here: everything that follows is
    // done by the invited person, who is a guest until they accept.
    auth()->logout();

    /** @var User $user */
    $user = User::query()->where('email', 'invited@example.com')->sole();

    return [$user, (string) $captured];
}

/** Everything the completion screen asks for. */
function acceptancePayload(array $overrides = []): array
{
    return array_merge([
        'first_name_ar' => 'محمد',
        'father_name_ar' => 'عبدالله',
        'family_name_ar' => 'القحطاني',
        'first_name_en' => 'Mohammed',
        'family_name_en' => 'Alqahtani',
        'phone' => '0512345670',
        'gender' => 'male',
        'password' => 'Canary-Sets-This-1!',
        'password_confirmation' => 'Canary-Sets-This-1!',
    ], $overrides);
}

it('D-85: الدعوة تُرسَل باسم وبريد فقط — بلا كلمة مرور وبلا بقية الحقول', function (): void {
    [$user, $token] = inviteAndCaptureToken();

    expect($token)->not->toBe('')
        ->and($user->getAttribute('email_verified_at'))->toBeNull()
        ->and((bool) $user->getAttribute('must_change_password'))->toBeFalse()
        ->and($user->getAttribute('temp_password_expires_at'))->toBeNull()
        ->and($user->getAttribute('invited_at'))->not->toBeNull()
        ->and($user->isPendingInvitation())->toBeTrue();

    $profile = Profile::query()->where('user_id', $user->id)->sole();

    // What the administrator typed, and nothing invented beside it.
    expect($profile->getAttribute('first_name_ar'))->toBe('محمد')
        ->and($profile->getAttribute('last_name_ar'))->toBe('القحطاني')
        ->and($profile->getAttribute('second_name_ar'))->toBeNull()
        ->and($profile->getAttribute('phone'))->toBeNull()
        ->and($profile->getAttribute('gender'))->toBeNull()
        ->and($profile->getAttribute('first_name_en'))->toBeNull();

    // Seated on invitation, so the roster shows them before they arrive.
    expect(Enrollment::query()->where('user_id', $user->id)->where('cohort_id', $this->cohort->id)->count())->toBe(1);

    // The letter carries a link and no credential.
    Mail::assertQueued(EmailTokenLink::class, fn (EmailTokenLink $letter): bool => $letter->type === EmailTokenType::Invite
        && $letter->hasTo('invited@example.com')
        && str_contains($letter->url, route('invitation.accept', ['token' => $token])));

    Mail::assertNotQueued(App\Mail\InvitationLetter::class);
});

it('D-85: رسالة الدعوة تُصيَّر فعلًا — رابط بلا كلمة مرور وبلا مفتاح خام', function (): void {
    [$user, $token] = inviteAndCaptureToken();

    $html = (new EmailTokenLink(
        $user->load('profile'),
        EmailTokenType::Invite,
        route('invitation.accept', ['token' => $token]),
    ))->render();

    expect($html)->toContain(route('invitation.accept', ['token' => $token]))
        ->and($html)->toContain((string) __('emails.invitation_link.cta'))
        // A copy key that failed to resolve would print itself to the reader.
        ->and($html)->not->toContain('emails.invitation_link')
        ->and($html)->not->toContain('emails.common');
});

it('المادة 5: الحساب المدعوّ لا يُدخَل إليه قبل قبول الدعوة', function (): void {
    [$user] = inviteAndCaptureToken();

    // The row's password is random and was never sent anywhere, so nothing
    // anybody could type is it — and the address is unproven besides. The
    // invitation link is the only way in.
    $this->post(route('login'), ['email' => 'invited@example.com', 'password' => 'Canary-Sets-This-1!'])
        ->assertSessionHasErrors();

    expect(auth()->check())->toBeFalse()
        ->and($user->fresh()?->getAttribute('email_verified_at'))->toBeNull();

    // And recovery does not open it either: what it sends is the invitation.
    $this->post(route('password.email'), ['email' => 'invited@example.com']);

    expect(EmailToken::query()->forUser($user)->ofType(EmailTokenType::Reset)->count())->toBe(0);
});

it('D-85: الرابط يفتح النموذج بالبريد معروضًا لا قابلًا للتغيير، وبالاسم الذي كتبه المدير', function (): void {
    [, $token] = inviteAndCaptureToken();

    $this->get(route('invitation.accept', ['token' => $token]))
        ->assertOk()
        ->assertViewIs('auth.accept-invitation')
        ->assertSee('invited@example.com', false)
        // Shown read-only, and posted under a name the server never reads.
        ->assertSee('name="invited_email"', false)
        ->assertDontSee('name="email"', false)
        // The name is offered back, editable.
        ->assertSee('value="محمد"', false)
        ->assertSee('name="phone"', false)
        ->assertSee('name="gender"', false)
        ->assertSee('name="password"', false);
});

it('D-85: القبول يحفظ البيانات ويفعّل البريد ويُدخِل صاحبه — ويستهلك الرابط', function (): void {
    [$user, $token] = inviteAndCaptureToken();

    $this->post(route('invitation.store', ['token' => $token]), acceptancePayload())
        ->assertRedirect(route('dashboard'));

    $fresh = $user->fresh();
    $profile = Profile::query()->where('user_id', $user->id)->sole();

    expect(auth()->id())->toBe($user->id)
        ->and($fresh?->getAttribute('email_verified_at'))->not->toBeNull()
        ->and((bool) $fresh?->getAttribute('must_change_password'))->toBeFalse()
        ->and($profile->getAttribute('second_name_ar'))->toBe('عبدالله')
        ->and($profile->getAttribute('phone'))->toBe('0512345670')
        ->and($profile->getAttribute('gender')?->value)->toBe('male')
        ->and($profile->getAttribute('first_name_en'))->toBe('Mohammed')
        // An optional part nobody filled stays absent rather than empty.
        ->and($profile->getAttribute('third_name_ar'))->toBeNull();

    // The password they chose is the password that works.
    auth()->logout();
    $this->post(route('login'), ['email' => 'invited@example.com', 'password' => 'Canary-Sets-This-1!'])
        ->assertRedirect(route('dashboard'));

    // And the link is spent: a second use opens the refusal, not the form.
    auth()->logout();
    $this->get(route('invitation.accept', ['token' => $token]))
        ->assertOk()
        ->assertSee((string) __('auth.invitation.invalid_title'), false)
        ->assertDontSee('name="password"', false);

    expect(EmailToken::query()->where('type', 'invite')->whereNull('used_at')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'account.invitation_accepted')->where('entity_id', $user->id)->count())->toBe(1);
});

it('D-85: البريد لا يتغيّر من هذه الشاشة مهما أُرسِل معها', function (): void {
    [$user, $token] = inviteAndCaptureToken();

    $this->post(route('invitation.store', ['token' => $token]), acceptancePayload([
        'email' => 'attacker@example.com',
        'invited_email' => 'attacker@example.com',
    ]))->assertRedirect(route('dashboard'));

    expect($user->fresh()?->getAttribute('email'))->toBe('invited@example.com')
        ->and(User::query()->where('email', 'attacker@example.com')->exists())->toBeFalse();
});

it('BR-30: رابط منتهٍ أو مستعمل أو مجهول يُرفَض بالنص نفسه ولا يكتب شيئًا', function (): void {
    [$user, $token] = inviteAndCaptureToken();

    // A day past the window the invitation letter promised.
    $days = (int) config('athar.invitations.temp_password_days', 7);
    freezeAt(riyadhAt('2026-09-20 09:00:00')->addDays($days + 1));

    $this->get(route('invitation.accept', ['token' => $token]))
        ->assertOk()
        ->assertSee((string) __('auth.invitation.invalid_title'), false);

    $this->post(route('invitation.store', ['token' => $token]), acceptancePayload())
        ->assertSessionHasErrors('token');

    // An unknown token says exactly the same thing.
    $this->get(route('invitation.accept', ['token' => str_repeat('z', 64)]))
        ->assertOk()
        ->assertSee((string) __('auth.invitation.invalid_title'), false);

    expect(auth()->check())->toBeFalse()
        ->and($user->fresh()?->getAttribute('email_verified_at'))->toBeNull()
        ->and(Profile::query()->where('user_id', $user->id)->value('phone'))->toBeNull();
});

it('D-85: إعادة الإرسال تُبطل الرابط الأول — ولا يبقى طريقان', function (): void {
    [$user, $first] = inviteAndCaptureToken();

    $second = null;
    Event::listen(function (EmailTokenIssued $event) use (&$second): void {
        $second = $event->plainToken;
    });

    $this->actingAs($this->admin)
        ->post(route('admin.users.resendVerification', $user))
        ->assertSessionHasNoErrors();

    auth()->logout();

    expect($second)->not->toBe($first);

    $this->get(route('invitation.accept', ['token' => $first]))
        ->assertSee((string) __('auth.invitation.invalid_title'), false);

    $this->get(route('invitation.accept', ['token' => (string) $second]))
        ->assertSee('name="password"', false);
});

it('D-85: من دُعي ونسي دعوته يطلب الاستعادة فتصله دعوته — لا رابط استعادة لا يفيده', function (): void {
    [$user] = inviteAndCaptureToken();

    $this->post(route('password.email'), ['email' => 'invited@example.com'])
        ->assertSessionHasNoErrors();

    expect(EmailToken::query()->forUser($user)->ofType(EmailTokenType::Invite)->whereNull('used_at')->count())->toBe(1)
        ->and(EmailToken::query()->forUser($user)->ofType(EmailTokenType::Reset)->count())->toBe(0);
});

it('D-85: الدعوة تُرسَل لمدرّب أيضًا وبلا دفعة، ويكملها هو', function (): void {
    $captured = null;
    Event::listen(function (EmailTokenIssued $event) use (&$captured): void {
        $captured = $event->plainToken;
    });

    $trainer = app(AccountInviter::class)->inviteByLink(
        email: 'coach@example.com',
        role: UserRole::Trainer,
        profileColumns: ['first_name_ar' => 'سارة'],
        cohort: null,
    );

    expect($trainer->isPendingInvitation())->toBeTrue()
        ->and(Enrollment::query()->where('user_id', $trainer->id)->count())->toBe(0);

    $this->post(route('invitation.store', ['token' => (string) $captured]), acceptancePayload([
        'first_name_ar' => 'سارة',
        'gender' => 'female',
        'phone' => '0512345679',
    ]))->assertRedirect(route('dashboard'));

    expect($trainer->fresh()?->getAttribute('email_verified_at'))->not->toBeNull();
});

it('المادة 5: نموذج الدعوة يقبل اسمًا أول وبريدًا، ويرفض ما ليس اسمًا', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), [
            'first_name_ar' => 'محمد',
            'email' => 'onlyfirst@example.com',
            'role' => 'participant',
            'status' => 'active',
            'cohort_id' => $this->cohort->id,
        ])
        ->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'onlyfirst@example.com')->exists())->toBeTrue();

    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), [
            'first_name_ar' => 'Mohammed',
            'email' => 'latin@example.com',
            'role' => 'participant',
            'status' => 'active',
            'cohort_id' => $this->cohort->id,
        ])
        ->assertSessionHasErrors('first_name_ar');

    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), [
            'email' => 'noname@example.com',
            'role' => 'participant',
            'status' => 'active',
            'cohort_id' => $this->cohort->id,
        ])
        ->assertSessionHasErrors('first_name_ar');
});

it('D-85: صاحب اسم ثنائي بلا اسم إنجليزي يحفظ ملفه الشخصي ولا يُطالَب بما لا يملك', function (): void {
    // Exactly what the import now creates: two name parts, no Latin name, no
    // gender. The edit screen must not be stricter than the door they came in
    // through.
    $imported = makeParticipant($this->cohort, ['email' => 'imported@example.com']);
    Profile::factory()->create([
        'user_id' => $imported->id,
        'first_name_ar' => 'محمد',
        'second_name_ar' => null,
        'third_name_ar' => null,
        'last_name_ar' => 'القحطاني',
        'first_name_en' => null,
        'second_name_en' => null,
        'third_name_en' => null,
        'last_name_en' => null,
        'phone' => '0512345676',
        'gender' => null,
    ]);

    $this->actingAs($imported)
        ->patch(route('profile.update'), [
            'first_name_ar' => 'محمد',
            'father_name_ar' => '',
            'grandfather_name_ar' => '',
            'family_name_ar' => 'القحطاني',
            'first_name_en' => '',
            'father_name_en' => '',
            'grandfather_name_en' => '',
            'family_name_en' => '',
            'phone' => '0512345677',
            'city' => 'الرياض',
        ])
        ->assertSessionHasNoErrors();

    $profile = Profile::query()->where('user_id', $imported->id)->sole();

    expect($profile->getAttribute('phone'))->toBe('0512345677')
        ->and($profile->getAttribute('last_name_ar'))->toBe('القحطاني')
        ->and($profile->getAttribute('second_name_ar'))->toBeNull();
});
