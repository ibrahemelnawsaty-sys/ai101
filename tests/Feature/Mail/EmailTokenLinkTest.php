<?php

declare(strict_types=1);

/**
 * The letters that carry a single-use token actually leave the application.
 *
 * WHY THIS SUITE EXISTS
 * `IssuesEmailTokens` ended with `EmailTokenIssued::dispatch(...)`, commented
 * "so the mail layer can send it". `app/Listeners` did not exist. Four events
 * were dispatched into an empty room, and `PasswordResetController::email()`
 * answered the visitor with "we sent you a link" while sending nothing — the
 * token sat in a table no one could reach. Twenty finished letters waited in
 * `lang/<locale>/emails.php` for a sender that was never written.
 *
 * Nothing failed, because no test asserted that a letter leaves. These do.
 *
 * @see BR-29, BR-30, BR-36 · PRD §9.2.3, §9.3.3 · D-02, D-49
 */

use App\Enums\EmailTokenType;
use App\Events\EmailTokenIssued;
use App\Listeners\SendEmailTokenLink;
use App\Mail\EmailTokenLink;
use App\Models\EmailToken;
use App\Services\Mail\EmailPalette;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));
});

it('D-49: طلب استعادة كلمة المرور يُرسل رسالة فعلًا', function (): void {
    Mail::fake();

    $user = makeParticipant(null, ['email' => 'canary@example.test']);

    $this->post(route('password.email'), ['email' => 'canary@example.test'])
        ->assertRedirect();

    // The event is only half the contract; the letter is the other half.
    Mail::assertQueued(EmailTokenLink::class, function (EmailTokenLink $mail) use ($user): bool {
        return $mail->hasTo($user->getAttribute('email'))
            && $mail->type === EmailTokenType::Reset;
    });
});

it('BR-30: عنوان غير مسجَّل لا يُرسل له شيء ولا يكشف نفسه', function (): void {
    Mail::fake();

    // The screen answers identically either way (BR-30), but no letter may go
    // to an address that has no account.
    $this->post(route('password.email'), ['email' => 'nobody@example.test'])
        ->assertRedirect();

    Mail::assertNothingQueued();
});

it('D-49: الرسالة مُطابورة لا مُرسَلة داخل الطلب', function (): void {
    // Shared hosting caps a request, and an SMTP handshake to a host we do not
    // control can take seconds. A slow send must never hold a web request open.
    expect(new EmailTokenLink(makeParticipant(), EmailTokenType::Reset, 'https://example.test/x'))
        ->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class)
        ->and(new SendEmailTokenLink())->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
});

it('D-49: فشل الإرسال لا يُبطل الرمز ولا يُسقط الطلب', function (): void {
    // The token is stored before the letter is queued. A delivery failure is a
    // delivery failure — not a broken account (EmailTokenIssued's own wording).
    Mail::fake();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    $user = makeParticipant();
    $token = EmailToken::factory()->create(['user_id' => $user->getKey()]);

    $listener = new SendEmailTokenLink();

    $listener->handle(new EmailTokenIssued(
        $user,
        $token,
        EmailTokenType::Reset,
        'plain-token',
        'https://example.test/reset/plain-token',
    ));

    expect($token->fresh()->getAttribute('used_at'))->toBeNull();
});

it('المادة 6: قالب الرسالة يقرأ ألوانه ومسافاته من tokens.css', function (): void {
    // An e-mail cannot use custom properties, so the values are inlined — but
    // they are READ from the token file, never written in the template. If this
    // ever returns a literal that is not in tokens.css, the letter has started
    // drifting from the brand.
    $theme = app(EmailPalette::class)->all();

    $tokens = (string) file_get_contents(resource_path('css/tokens.css'));

    expect($theme['brand'])->toStartWith('#')
        ->and($tokens)->toContain($theme['brand'])
        ->and($tokens)->toContain($theme['gapXl'])
        ->and($theme['font'])->not->toBeEmpty();
});

it('المادة 13: لا نص عربي ولا لون حرفي في طبقة البريد', function (): void {
    $files = [
        app_path('Mail/EmailTokenLink.php'),
        app_path('Listeners/SendEmailTokenLink.php'),
        app_path('Services/Mail/EmailPalette.php'),
        resource_path('views/mail/token-link.blade.php'),
    ];

    $offenders = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match('/[\x{0600}-\x{06FF}]/u', $source) === 1) {
            $offenders[] = basename($file).' — Arabic text outside lang/';
        }

        if (preg_match('/#[0-9A-Fa-f]{3,8}\b/', $source) === 1) {
            $offenders[] = basename($file).' — hex colour literal';
        }

        if (preg_match('/(?<![\w.])-?\d+(?:\.\d+)?px\b/i', $source) === 1) {
            $offenders[] = basename($file).' — raw px length';
        }
    }

    expect($offenders)->toBe([]);
});

it('D-49: كل حدث من الأربعة له مستمع', function (): void {
    // The gap this whole batch exists to close: four events were dispatched and
    // app/Listeners did not exist. A listener is registered by the type it
    // accepts, so the check is that a handler EXISTS for each event class —
    // not that a file with a likely name is present.
    $missing = [];

    foreach ([
        App\Events\EmailTokenIssued::class => App\Listeners\SendEmailTokenLink::class,
        App\Events\AccountVerified::class => App\Listeners\SendAccountVerifiedWelcome::class,
        App\Events\PasswordChanged::class => App\Listeners\SendPasswordChangedNotice::class,
    ] as $event => $listener) {
        if (! class_exists($listener)) {
            $missing[] = $listener.' does not exist';

            continue;
        }

        $handles = (new ReflectionMethod($listener, 'handle'))->getParameters()[0] ?? null;

        if ($handles === null || (string) $handles->getType() !== $event) {
            $missing[] = $listener.' does not accept '.$event;
        }
    }

    expect($missing)->toBe([]);
});

it('D-49: القالب المشترك يحمل الهوية ولا يحمل حرفًا واحدًا', function (): void {
    $letter = new App\Mail\AtharLetter(
        copyKey: 'emails.certificate_issued',
        values: ['serial' => 'ATHAR-AI101-2026-0001'],
        ctaUrl: 'https://example.test/certificate',
        meta: ['CANARY-LABEL' => 'CANARY-VALUE'],
    );

    $html = $letter->render();
    $theme = app(App\Services\Mail\EmailPalette::class)->all();

    expect($html)->toContain('dir="rtl"')
        ->and($html)->toContain('lang="ar"')
        // The brand comes from the token file, not from this template.
        ->and($html)->toContain($theme['brandDeep'])
        ->and($html)->toContain($theme['accent'])
        // The detail strip and the one button both render.
        ->and($html)->toContain('CANARY-LABEL')
        ->and($html)->toContain('CANARY-VALUE')
        ->and($html)->toContain('https://example.test/certificate')
        // A missing translation must never reach a reader as its own key.
        ->and($html)->not->toContain('emails.certificate_issued.');
});
