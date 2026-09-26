<?php

declare(strict_types=1);

/**
 * `athar:demo-accounts --reset-passwords` puts one known password on the demo
 * accounts that already exist (D-120).
 *
 * The passwords the command generates are printed once and kept nowhere, and
 * nothing else in the platform reaches these accounts again: a second run
 * reuses them without touching their passwords, and a reset link goes to an
 * address with no inbox (D-117). So the console becomes the reset path, and a
 * console path that did less than the reset SCREEN would be the weak door the
 * screen's rules exist to close (art. 5). These tests hold it to all of it:
 *
 *   rules     — the screen's password rules and blacklist (PRD §9.2.1)
 *   sessions  — every session and the remember-me token end (BR-29)
 *   links     — an unused reset or invitation link is spent, as using one is
 *   notice    — the security letter goes out (PRD §9.3.3, §9.16.1)
 *   trail     — each change is written, without the password (art. 8)
 *   scope     — the roster's addresses only, shown and confirmed first;
 *               nothing created, nothing revived, all or nothing
 *
 * @see BR-29 · PRD §9.2.1, §9.3.3, §9.16.1 · CONSTITUTION art. 5, art. 8 · D-117, D-120
 */

use App\Enums\EmailTokenType;
use App\Mail\AtharLetter;
use App\Models\AuditLog;
use App\Models\EmailToken;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** The question the command asks before it changes anything. */
const DEMO_RESET_CONFIRM = 'Set one new password on these 4 account(s)?';

/**
 * The four roster addresses at the default domain.
 *
 * @return list<string>
 */
function demoResetAddresses(): array
{
    return [
        'admin@athar-demo.test',
        'supervisor@athar-demo.test',
        'trainer@athar-demo.test',
        'student@athar-demo.test',
    ];
}

/**
 * Every account's stored hash, keyed by address — deleted ones included, so a
 * change to any row at all shows up.
 *
 * @return array<string, string>
 */
function demoResetHashes(): array
{
    /** @var array<string, string> $hashes */
    $hashes = User::withTrashed()->orderBy('email')->pluck('password_hash', 'email')->all();

    return $hashes;
}

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-26 12:00:00'));

    makeCohort(['status' => 'open', 'capacity' => 60]);

    // The live host's situation: four demo accounts whose passwords were
    // printed once and are gone.
    $this->artisan('athar:demo-accounts', ['--password' => 'Demo-Passw0rd!'])->assertSuccessful();
});

it('D-120: كلمة مرور واحدة تُضبط على الحسابات التجريبية الأربعة فيدخل بها كلٌّ منها، ولا تبقى القديمة', function (): void {
    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Reset-Passw0rd!'])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->expectsOutputToContain('One password now opens all 4 accounts')
        ->doesntExpectOutputToContain('Reset-Passw0rd!')
        ->assertSuccessful();

    foreach (demoResetAddresses() as $email) {
        $user = User::query()->where('email', $email)->sole();

        expect(Hash::check('Reset-Passw0rd!', $user->getAuthPassword()))->toBeTrue("{$email} did not get the new password")
            ->and(Hash::check('Demo-Passw0rd!', $user->getAuthPassword()))->toBeFalse("{$email} kept the old password");

        // Proven at the door itself, not only in the column.
        $this->post(route('login'), ['email' => $email, 'password' => 'Reset-Passw0rd!']);
        $this->assertAuthenticatedAs($user);

        auth()->logout();
    }
});

it('D-120: بلا --password تُطلب كلمة المرور مرتين دون إظهار، وتُعتمد إذا تطابقتا', function (): void {
    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->expectsQuestion('New password for the demo accounts (not echoed)', 'Typed-Passw0rd!')
        ->expectsQuestion('Repeat it', 'Typed-Passw0rd!')
        ->doesntExpectOutputToContain('Typed-Passw0rd!')
        ->assertSuccessful();

    foreach (demoResetAddresses() as $email) {
        $hash = User::query()->where('email', $email)->value('password_hash');

        expect(Hash::check('Typed-Passw0rd!', (string) $hash))->toBeTrue("{$email} did not get the typed password");
    }
});

it('D-120: الحسابات المطابقة تُعرض قبل أي سؤال عن كلمة المرور، ورفض التأكيد لا يغيّر شيئًا', function (): void {
    $before = demoResetHashes();

    $pending = $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Reset-Passw0rd!']);

    foreach (demoResetAddresses() as $email) {
        $pending->expectsOutputToContain($email);
    }

    $pending->expectsConfirmation(DEMO_RESET_CONFIRM, 'no')
        ->expectsOutputToContain('Nothing was changed')
        ->assertFailed();

    expect(demoResetHashes())->toBe($before)
        ->and(AuditLog::query()->where('action', 'user.password_reset')->count())->toBe(0);
});

it('D-120: بلا أحد يجيب (--no-interaction) يُعدّ التأكيد رفضًا ولا يتغيّر شيء', function (): void {
    $before = demoResetHashes();

    // Artisan::call, not $this->artisan: the test double behind the latter
    // answers every question itself, so it cannot show what the real console
    // does when nobody can be asked — return the default, which is "no".
    $exit = Artisan::call('athar:demo-accounts', [
        '--reset-passwords' => true,
        '--password' => 'Reset-Passw0rd!',
        '--no-interaction' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Nothing was changed')
        ->and(demoResetHashes())->toBe($before);
});

it('D-120: نطاق خاطئ يطابق حسابًا حقيقيًّا يُعرض قبل التغيير، فيُرفض ولا يُمسّ', function (): void {
    // The roster fixes only the part before the @: --domain=<a real domain>
    // reaches a real person's admin@ if one exists.
    $real = withPassword(makeAdmin(['email' => 'admin@example.test']), 'Real-Passw0rd!');

    $this->artisan('athar:demo-accounts', [
        '--reset-passwords' => true,
        '--password' => 'Reset-Passw0rd!',
        '--domain' => 'example.test',
    ])
        ->expectsOutputToContain('admin@example.test')
        ->expectsConfirmation('Set one new password on these 1 account(s)?', 'no')
        ->assertFailed();

    expect(Hash::check('Real-Passw0rd!', (string) $real->fresh()?->getAuthPassword()))->toBeTrue();
});

it('D-120: كلمتان غير متطابقتين في الإدخال المخفي تُرفضان ولا تتغيّر أي كلمة مرور', function (): void {
    $before = demoResetHashes();

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->expectsQuestion('New password for the demo accounts (not echoed)', 'Typed-Passw0rd!')
        ->expectsQuestion('Repeat it', 'Typed-Passw0rd?')
        ->expectsOutputToContain('do not match')
        ->assertFailed();

    expect(demoResetHashes())->toBe($before)
        ->and(AuditLog::query()->where('action', 'user.password_reset')->count())->toBe(0);
});

it('D-120: كلمة مرور أضعف من قواعد شاشة الاستعادة تُرفض ولا يتغيّر شيء', function (string $weak): void {
    $before = demoResetHashes();

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => $weak])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->expectsOutputToContain('No password was changed')
        ->assertFailed();

    expect(demoResetHashes())->toBe($before)
        ->and(AuditLog::query()->where('action', 'user.password_reset')->count())->toBe(0);
})->with([
    'shorter than eight' => 'Ab1!xyz',
    'no symbol' => 'Abcdefg12',
    'no digit' => 'Abcdefg!!',
    'no capital' => 'abcdefg1!',
    'no small letter' => 'ABCDEFG1!',
]);

it('D-120: كلمة مرور على القائمة السوداء تُرفض كما ترفضها الشاشة ولو استوفت الشروط', function (): void {
    // PRD §9.2.1 — the screens refuse a blacklisted password after the rules
    // pass. The console must refuse the same one, or the list is decoration.
    config(['athar.security.password_blacklist' => ['Strong-Passw0rd!']]);

    $before = demoResetHashes();

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Strong-Passw0rd!'])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->expectsOutputToContain('blacklist')
        ->assertFailed();

    expect(demoResetHashes())->toBe($before);
});

it('BR-29: إعادة التعيين من الطرفية تُنهي كل جلسات الحساب التجريبي وتُسقط رمز «تذكّرني»', function (): void {
    // As in SecurityRulesTest: the rule is about ROWS, which exist only on the
    // `database` driver, and `user_sessions` is the framework table —
    // `sessions` holds the training sessions of PRD §7.3.
    config([
        'session.driver' => 'database',
        'session.table' => 'user_sessions',
    ]);

    $student = User::query()->where('email', 'student@athar-demo.test')->sole();
    $student->setAttribute('remember_token', Str::random(60));
    $student->save();

    // Someone outside the roster, signed in at the same time.
    $outsider = makeParticipant();

    foreach ([$student, $student, $outsider] as $holder) {
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $holder->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'AnotherDevice/1.0',
            'payload' => base64_encode(serialize([])),
            'last_activity' => riyadhAt('2026-09-26 11:00:00')->getTimestamp(),
        ]);
    }

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Reset-Passw0rd!'])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->expectsOutputToContain('Their sessions were ended')
        ->assertSuccessful();

    expect(DB::table('user_sessions')->where('user_id', $student->id)->count())->toBe(0)
        ->and($student->fresh()?->getAttribute('remember_token'))->toBeNull()
        ->and(DB::table('user_sessions')->where('user_id', $outsider->id)->count())->toBe(1);
});

it('D-120: رابط استعادة لم يُستعمل يسقط بإعادة التعيين، فلا يضع بعدها كلمة مرور أخرى', function (): void {
    $student = User::query()->where('email', 'student@athar-demo.test')->sole();
    $plain = 'CANARY-'.str_repeat('r', 57);

    // Issued the way the accounts screen issues one — and on a demo address,
    // it comes back inside the bounce to the shared mailbox.
    EmailToken::query()->create([
        'user_id' => $student->getKey(),
        'token_hash' => hash('sha256', $plain),
        'type' => EmailTokenType::Reset->value,
        'expires_at' => Clock::now()->addMinutes(30),
        'used_at' => null,
    ]);

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Reset-Passw0rd!'])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->assertSuccessful();

    expect(EmailToken::query()->forUser($student)->usableAt(Clock::now())->exists())->toBeFalse();

    $this->post(route('password.update', ['token' => $plain]), [
        'password' => 'Hijack-Passw0rd!',
        'password_confirmation' => 'Hijack-Passw0rd!',
    ]);

    $hash = (string) $student->fresh()?->getAuthPassword();

    expect(Hash::check('Reset-Passw0rd!', $hash))->toBeTrue()
        ->and(Hash::check('Hijack-Passw0rd!', $hash))->toBeFalse();
});

it('D-120: يُرسَل إشعار تغيير كلمة المرور إلى عنوان كل حساب، كما بعد الاستعادة من الشاشة', function (): void {
    Mail::fake();

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Reset-Passw0rd!'])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->assertSuccessful();

    foreach (demoResetAddresses() as $email) {
        Mail::assertQueued(
            AtharLetter::class,
            fn (AtharLetter $letter): bool => $letter->copyKey === 'emails.password_changed' && $letter->hasTo($email),
        );
    }

    Mail::assertQueuedCount(4);
});

it('D-120: الحساب المقفل مؤقتًا يُفتح ويُصفَّر عدّاد محاولاته فيدخل فورًا', function (): void {
    $trainer = User::query()->where('email', 'trainer@athar-demo.test')->sole();
    $trainer->setAttribute('failed_login_count', 5);
    $trainer->setAttribute('locked_until', riyadhAt('2026-09-26 12:15:00'));
    $trainer->save();

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Reset-Passw0rd!'])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->assertSuccessful();

    $fresh = $trainer->fresh();

    expect($fresh?->getAttribute('failed_login_count'))->toBe(0)
        ->and($fresh?->getAttribute('locked_until'))->toBeNull();

    $this->post(route('login'), ['email' => 'trainer@athar-demo.test', 'password' => 'Reset-Passw0rd!']);
    $this->assertAuthenticatedAs($trainer);
});

it('D-120: كل تغيير يُكتب في سجل التدقيق مصدره الطرفية، بلا كلمة المرور ولا بصمتها', function (): void {
    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Reset-Passw0rd!'])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->assertSuccessful();

    $logs = AuditLog::query()->where('action', 'user.password_reset')->get();

    expect($logs)->toHaveCount(4);

    foreach (demoResetAddresses() as $email) {
        $user = User::query()->where('email', $email)->sole();
        $log = $logs->firstWhere('entity_id', $user->id);

        expect($log)->not->toBeNull("no trail row for {$email}");

        $row = (string) json_encode($log?->toArray());

        expect($log?->getAttribute('after'))->toMatchArray(['via' => 'console'])
            ->and($row)->not->toContain('Reset-Passw0rd!')
            ->and($row)->not->toContain($user->getAuthPassword());
    }
});

it('D-120: فشلٌ في منتصف الدفعة لا يغيّر أي حساب ولا يرسل بريدًا ولا يطبع البصمة', function (): void {
    Mail::fake();

    // The third account in the order the command works in fails to save, with
    // a message that quotes the new hash the way a database error quotes the
    // failed statement.
    User::saving(function (User $user): void {
        if ($user->getAttribute('email') === 'supervisor@athar-demo.test') {
            throw new RuntimeException('update "users" set "password_hash" = '.(string) $user->getAttribute('password_hash'));
        }
    });

    $before = demoResetHashes();

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Reset-Passw0rd!'])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->expectsOutputToContain('No password was changed')
        ->doesntExpectOutputToContain('$2y$')
        ->assertFailed();

    expect(demoResetHashes())->toBe($before)
        ->and(AuditLog::query()->where('action', 'user.password_reset')->count())->toBe(0);

    Mail::assertNothingQueued();
});

it('D-120: حساب حُذف أثناء انتظار الإجابة لا يُعطى كلمة مرور معروفة، ويُذكر', function (): void {
    // Deleted between the listing and the change: the moment the command's
    // transaction opens, before it reads the accounts again.
    $deleted = false;

    Event::listen(TransactionBeginning::class, function () use (&$deleted): void {
        if ($deleted) {
            return;
        }

        $deleted = true;
        User::query()->where('email', 'student@athar-demo.test')->sole()->delete();
    });

    $removedHash = User::query()->where('email', 'student@athar-demo.test')->value('password_hash');

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Reset-Passw0rd!'])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->expectsOutputToContain('removed   student@athar-demo.test')
        ->assertSuccessful();

    expect(User::withTrashed()->where('email', 'student@athar-demo.test')->value('password_hash'))->toBe($removedHash)
        ->and(AuditLog::query()->where('action', 'user.password_reset')->count())->toBe(3);
});

it('D-120: لا يُنشئ حسابًا ولا يُحيي محذوفًا ولا يمسّ حسابًا خارج القائمة، ويذكر الناقص', function (): void {
    // A real account the reset must not reach, and a demo account removed
    // before it: brought back with a known password, it would be a live
    // login nobody knows exists.
    $outsider = withPassword(makeAdmin(['email' => 'real.supervisor@example.test']), 'Real-Passw0rd!');
    User::query()->where('email', 'supervisor@athar-demo.test')->sole()->delete();

    $accounts = User::withTrashed()->count();
    $removedHash = User::withTrashed()->where('email', 'supervisor@athar-demo.test')->value('password_hash');

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Reset-Passw0rd!'])
        ->expectsConfirmation('Set one new password on these 3 account(s)?', 'yes')
        ->expectsOutputToContain('not found supervisor@athar-demo.test')
        ->assertSuccessful();

    expect(User::withTrashed()->count())->toBe($accounts)
        ->and(User::query()->where('email', 'supervisor@athar-demo.test')->exists())->toBeFalse()
        ->and(User::withTrashed()->where('email', 'supervisor@athar-demo.test')->value('password_hash'))->toBe($removedHash)
        ->and(Hash::check('Real-Passw0rd!', (string) $outsider->fresh()?->getAuthPassword()))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.password_reset')->count())->toBe(3);
});

it('D-120: نطاق بحروف كبيرة أو مسافات يُطبَّع فيطابق الحسابات، ولا يُبلَّغ عنها «غير موجودة»', function (): void {
    $this->artisan('athar:demo-accounts', [
        '--reset-passwords' => true,
        '--password' => 'Reset-Passw0rd!',
        '--domain' => ' ATHAR-Demo.TEST ',
    ])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->doesntExpectOutputToContain('not found')
        ->assertSuccessful();

    expect(Hash::check('Reset-Passw0rd!', (string) User::query()->where('email', 'student@athar-demo.test')->value('password_hash')))->toBeTrue();
});

it('D-120: حالة الحساب لا تتغيّر — المعطَّل يبقى معطَّلًا ولا يدخل، ويُذكر ذلك', function (): void {
    $trainer = User::query()->where('email', 'trainer@athar-demo.test')->sole();
    $trainer->setAttribute('status', 'suspended');
    $trainer->save();

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Reset-Passw0rd!'])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->expectsOutputToContain('trainer@athar-demo.test is suspended')
        ->assertSuccessful();

    expect($trainer->fresh()?->status->value)->toBe('suspended')
        ->and(Hash::check('Reset-Passw0rd!', (string) $trainer->fresh()?->getAuthPassword()))->toBeTrue();

    $this->post(route('login'), ['email' => 'trainer@athar-demo.test', 'password' => 'Reset-Passw0rd!']);
    $this->assertGuest();
});

it('D-120: لا حساب تجريبي على النطاق المعطى — يفشل ويدلّ على --domain دون أن يسأل شيئًا', function (): void {
    $before = demoResetHashes();

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--domain' => 'elsewhere.test'])
        ->expectsOutputToContain('--domain')
        ->assertFailed();

    expect(demoResetHashes())->toBe($before);
});

it('D-120: الإنتاج يرفض إعادة التعيين دون --force، ومع --password ينبّه إلى سجل الصدفة', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $before = demoResetHashes();

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Reset-Passw0rd!'])
        ->expectsOutputToContain('--force')
        ->assertFailed();

    expect(demoResetHashes())->toBe($before);

    // With --force it is the deliberate act the guard asks for — still
    // confirmed, and the typed-in password is called out.
    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--password' => 'Reset-Passw0rd!', '--force' => true])
        ->expectsConfirmation(DEMO_RESET_CONFIRM, 'yes')
        ->expectsOutputToContain("shell's history")
        ->assertSuccessful();

    expect(Hash::check('Reset-Passw0rd!', (string) User::query()->where('email', 'admin@athar-demo.test')->value('password_hash')))->toBeTrue();
});

it('D-120: --reset-passwords و--remove معًا يُرفضان ولا يُحذف ولا يتغيّر شيء', function (): void {
    $before = demoResetHashes();

    $this->artisan('athar:demo-accounts', ['--reset-passwords' => true, '--remove' => true, '--password' => 'Reset-Passw0rd!'])
        ->assertFailed();

    expect(User::query()->whereIn('email', demoResetAddresses())->count())->toBe(4)
        ->and(demoResetHashes())->toBe($before);
});
