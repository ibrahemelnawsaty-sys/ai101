<?php

declare(strict_types=1);

/**
 * Phase 5 · D-152 — what the independent security review found about the new way in.
 *
 * Once the import invites by link, every imported participant comes through ONE route: the
 * acceptance of an invitation. Three things about that route mattered that they did not before:
 *
 *  · its limiter was `throttle:password`, keyed by the `email` field — which this form does not
 *    send (it posts `invited_email`) — so it fell back to the IP address. A class in one room
 *    behind one address could accept THREE invitations an hour; the fourth got «429» on a first,
 *    correct attempt. It now has its own limiter, keyed by the link (a token is one person) with
 *    a generous ceiling for the address (D-153, a stated assumption awaiting the owner).
 *  · accepting a link FORCED `status = active` and signed the person in, so an account an
 *    administrator had suspended (a wrong or withdrawn row of an import) re-activated itself with
 *    the link it was sent. A suspended account's link is now dead.
 *  · the administrator's «reset password» on an account that has not accepted yet sent a RECOVERY
 *    link — for an account with no password and no verified address, a dead end. It now sends the
 *    invitation again, as the public recovery route already does.
 *
 * @see BR-28, BR-29, BR-30 · PRD §4.5.1, §9.2.1 · CONSTITUTION art. 5, art. 7 · D-152, D-153
 */

use App\Enums\EmailTokenType;
use App\Events\EmailTokenIssued;
use App\Jobs\InviteImportedParticipant;
use App\Mail\EmailTokenLink;
use App\Models\User;
use App\Services\Credentials\AccountInviter;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 09:00:00'));
    Mail::fake();

    $this->cohort = makeCohort();
});

/** Import one person by the job and hand back their account and the raw token from the letter. */
function importOne(object $test, string $email): array
{
    $captured = null;

    Event::listen(function (EmailTokenIssued $event) use (&$captured): void {
        $captured = $event->plainToken;
    });

    (new InviteImportedParticipant($email, [
        'first_name_ar' => 'CANARY', 'second_name_ar' => 'A', 'third_name_ar' => 'B', 'last_name_ar' => 'C',
    ], (string) $test->cohort->getKey()))->handle(app(AccountInviter::class));

    return [User::query()->where('email', $email)->sole(), (string) $captured];
}

function hardeningPayload(int $n, array $overrides = []): array
{
    return array_merge([
        'first_name_ar' => 'محمد',
        'father_name_ar' => 'عبدالله',
        'family_name_ar' => 'القحطاني',
        'first_name_en' => 'Mohammed',
        'family_name_en' => 'Alqahtani',
        'phone' => '05123456'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
        'gender' => 'male',
        'password' => 'Canary-Sets-This-1!',
        'password_confirmation' => 'Canary-Sets-This-1!',
    ], $overrides);
}

it('D-152: خمسة مستوردين من عنوان IP واحد يقبلون دعواتهم كلهم — لا حجب بعد الثالث', function (): void {
    $links = [];

    foreach (range(1, 5) as $n) {
        $links[] = importOne($this, "person{$n}@example.test");
    }

    foreach ($links as $n => [$user, $token]) {
        Auth::forgetGuards();
        $this->flushSession();

        $this->post(route('invitation.store', ['token' => $token]), hardeningPayload($n + 1, ['token' => $token]))
            ->assertRedirect(route('dashboard'));

        expect($user->fresh()->email_verified_at)->not->toBeNull();
    }
});

it('D-153: الرابط الواحد له حدّ — عشر محاولات في الساعة ثم 429، ولو كانت كلها خاطئة', function (): void {
    [, $token] = importOne($this, 'one@example.test');

    $wrong = hardeningPayload(1, ['token' => $token, 'password_confirmation' => 'does-not-match']);

    foreach (range(1, 10) as $i) {
        Auth::forgetGuards();
        $this->post(route('invitation.store', ['token' => $token]), $wrong)->assertSessionHasErrors();
    }

    $this->post(route('invitation.store', ['token' => $token]), $wrong)->assertStatus(429);
});

it('D-153: لعنوان الشبكة سقف سخيّ — ستون في الساعة، ثم 429 لمن يخمّن روابط عشوائية', function (): void {
    foreach (range(1, 60) as $i) {
        $this->post(route('invitation.store', ['token' => str_repeat('x', 63).($i % 10)]), hardeningPayload(1))
            ->assertSessionHasErrors('token');
    }

    $this->post(route('invitation.store', ['token' => str_repeat('y', 64)]), hardeningPayload(1))->assertStatus(429);
});

it('BR-28: رابط حساب أُوقف لا يُعيد تفعيله ولا يُدخله — والصفحة تقول إن الرابط لا يعمل', function (): void {
    [$user, $token] = importOne($this, 'suspended@example.test');

    $user->forceFill(['status' => 'suspended'])->save();

    $this->get(route('invitation.accept', ['token' => $token]))
        ->assertOk()
        ->assertViewHas('tokenValid', false);

    Auth::forgetGuards();

    $this->post(route('invitation.store', ['token' => $token]), hardeningPayload(1, ['token' => $token]))
        ->assertSessionHasErrors('token');

    expect($user->fresh()->status->value)->toBe('suspended')
        ->and($user->fresh()->email_verified_at)->toBeNull()
        ->and(auth()->check())->toBeFalse();
});

it('D-152: «إعادة تعيين كلمة المرور» لحساب لم يقبل دعوته ترسل الدعوة من جديد لا رابط استعادة مسدودًا', function (): void {
    [$pending] = importOne($this, 'pending@example.test');
    $sys = makeSystemAdmin();

    $this->actingAs($sys)->post(route('admin.users.resetPassword', $pending))
        ->assertRedirect()
        ->assertSessionHas('status', __('admin.users.invitation_resent'));

    Mail::assertQueued(EmailTokenLink::class, fn (EmailTokenLink $letter): bool => $letter->user->is($pending) && $letter->type === EmailTokenType::Invite);
    Mail::assertNotQueued(EmailTokenLink::class, fn (EmailTokenLink $letter): bool => $letter->type === EmailTokenType::Reset);
});

it('D-152: «إعادة تعيين كلمة المرور» لحساب مفعَّل ما زالت ترسل رابط استعادة', function (): void {
    $active = makeParticipant($this->cohort);
    $sys = makeSystemAdmin();

    $this->actingAs($sys)->post(route('admin.users.resetPassword', $active))
        ->assertRedirect()
        ->assertSessionHas('status', __('admin.users.reset_password_sent'));

    Mail::assertQueued(EmailTokenLink::class, fn (EmailTokenLink $letter): bool => $letter->type === EmailTokenType::Reset);
});

it('art. 10: حمولة وظيفة الاستيراد (أسماء وجوالات وبريد) مشفّرة على الطابور كرسالة الرابط', function (): void {
    expect(is_subclass_of(InviteImportedParticipant::class, ShouldBeEncrypted::class))->toBeTrue();
});
