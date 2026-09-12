<?php

declare(strict_types=1);

/**
 * Getting back in: the recovery link, the screen it opens, and the lock screen.
 *
 * WHY THIS SUITE EXISTS
 * Nothing rendered the reset screen, and it was a 500 for everyone: a nested
 * multi-line array inside `@json(...)` compiles to broken PHP. Every recovery
 * link landed on the error page — including the one the login screen hands to
 * an invited trainee whose temporary password has lapsed (D-63). Behind that 500
 * sat a loop: recovery never cleared the invitation flags, so the new password
 * signed in straight into "your invitation expired — use recovery" (D-67).
 *
 * And the lock screen promised a countdown fed by a variable nothing set, then
 * kept the sign-in button disabled after the lock had lifted, until the session
 * expired (D-67).
 *
 * @see PRD §9.3.1, §9.3.3 · BR-29, BR-30 · D-63, D-67
 */

use App\Enums\EmailTokenType;
use App\Http\Controllers\Auth\LoginController;
use App\Models\EmailToken;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 09:00:00'));
});

/** A live recovery token for $user, issued the way the controller issues one. */
function recoveryTokenFor(User $user): string
{
    $plain = 'CANARY-'.str_repeat('r', 57);

    EmailToken::query()->create([
        'user_id' => $user->getKey(),
        'token_hash' => hash('sha256', $plain),
        'type' => EmailTokenType::Reset->value,
        'expires_at' => Clock::now()->addMinutes(30),
        'used_at' => null,
    ]);

    return $plain;
}

it('PRD-9.3.3: شاشة الاستعادة تُصيَّر من رابط صالح وتعرض العنوان وجزيرة النصوص', function (): void {
    $user = makeParticipant(null, ['email' => 'recover@example.com']);

    $response = $this->get(route('password.reset', ['token' => recoveryTokenFor($user)]));

    $response->assertOk()
        ->assertSee('recover@example.com', false)
        ->assertSee('x-data="passwordStrength', false);

    preg_match('#<script type="application/json" id="passwordCopy">(.*?)</script>#s', (string) $response->getContent(), $island);
    $copy = json_decode($island[1] ?? '', true);

    expect($copy)->toBeArray()
        ->and($copy['strength'] ?? null)->toHaveCount(4)
        ->and($copy['met'] ?? '')->not->toBe('');
});

it('PRD-9.3.3: رابط مجهول أو مستعمل يعرض حالته ولا يعرض نموذجًا ميتًا', function (): void {
    $response = $this->get(route('password.reset', ['token' => 'CANARY-unknown']));

    $response->assertOk()
        ->assertSee(e((string) __('auth.reset.invalid_title')), false)
        ->assertDontSee('x-data="passwordStrength', false);
});

it('D-67: مدعوّ انتهت كلمته المؤقتة يستعيد حسابه ثم يدخل — لا حلقة', function (): void {
    $user = makeParticipant(null, [
        'email' => 'lapsed@example.com',
        'must_change_password' => true,
        'temp_password_expires_at' => Clock::now()->subDay(),
    ]);

    $this->post(route('password.update', ['token' => $token = recoveryTokenFor($user)]), [
        'token' => $token,
        'password' => 'Canary-Chose-1!',
        'password_confirmation' => 'Canary-Chose-1!',
    ])->assertRedirect(route('login'));

    $fresh = $user->fresh();
    expect($fresh?->getAttribute('must_change_password'))->toBeFalse()
        ->and($fresh?->getAttribute('temp_password_expires_at'))->toBeNull();

    // The password they just chose must open the platform — not the screen
    // that sent them to recovery in the first place.
    $this->post(route('login.store'), [
        'email' => 'lapsed@example.com',
        'password' => 'Canary-Chose-1!',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('D-67: الاستعادة تمحو حالة الدخول العالقة فلا تعود الشاشة بملاحظة قديمة', function (): void {
    $user = makeParticipant(null, ['email' => 'stale@example.com']);

    $this->withSession(['auth.account_state' => 'invitation_expired', 'auth.locked_until' => 0])
        ->post(route('password.update', ['token' => $token = recoveryTokenFor($user)]), [
            'token' => $token,
            'password' => 'Canary-Chose-1!',
            'password_confirmation' => 'Canary-Chose-1!',
        ])
        ->assertSessionMissing('auth.account_state')
        ->assertSessionMissing('auth.locked_until');
});

it('PRD-9.3.1: شاشة القفل تعدّ نحو لحظة الخادم، وتُطلق الزرّ حين ينقضي القفل', function (): void {
    $lockedUntil = Clock::now()->addMinutes(LoginController::LOCK_MINUTES);
    $user = withPassword(makeParticipant(null, [
        'email' => 'locked@example.com',
        'locked_until' => $lockedUntil,
        'failed_login_count' => LoginController::MAX_ATTEMPTS,
    ]), 'Canary-Right-1!');

    // The correct password, while the lock holds.
    $this->from(route('login'))->post(route('login.store'), [
        'email' => 'locked@example.com',
        'password' => 'Canary-Right-1!',
    ])->assertRedirect(route('login'));

    $locked = (string) $this->get(route('login'))->getContent();

    expect($locked)->toContain("countdown({ target: '".$lockedUntil->toIso8601ZuluString()."' })")
        ->and($locked)->toContain(e((string) __('auth.login.locked_title')))
        ->and($locked)->toMatch('/<button[^>]*type="submit"[^>]*disabled/');

    // One second after the lock lifts, the same browser can sign in again.
    freezeAt($lockedUntil->addSecond());

    $released = (string) $this->get(route('login'))->getContent();

    expect($released)->not->toContain(e((string) __('auth.login.locked_title')))
        ->and($released)->not->toMatch('/<button[^>]*type="submit"[^>]*disabled/');

    $this->post(route('login.store'), [
        'email' => 'locked@example.com',
        'password' => 'Canary-Right-1!',
    ])->assertRedirect(route('dashboard'));
});

it('D-67: لا قالب يمرّر مصفوفة متعدّدة الأسطر إلى @json — يُترجَم إلى PHP مكسور', function (): void {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (preg_match('/@json\(\s*\[/', $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
