<?php

declare(strict_types=1);

use App\Models\User;

/**
 * The command exists so a reviewer can walk the platform on a fresh deploy.
 * That is only true if the accounts it makes can actually sign in and land on
 * their own screens, so this asserts the outcome, not the rows.
 */
it('ينشئ أربعة حسابات يمكنها الدخول والوصول لشاشاتها', function (): void {
    makeCohort(['status' => 'open', 'capacity' => 60]);

    $this->artisan('athar:demo-accounts', ['--password' => 'Demo-Passw0rd!'])
        ->assertSuccessful();

    // D-117 — `admin@` is the system administrator, `supervisor@` the general
    // supervisor.
    $expected = [
        'admin@athar-demo.test' => '/admin/users',
        'supervisor@athar-demo.test' => '/admin',
        'trainer@athar-demo.test' => '/trainer/submissions',
        'student@athar-demo.test' => '/dashboard',
    ];

    foreach ($expected as $email => $path) {
        $user = User::query()->where('email', $email)->first();
        expect($user)->not->toBeNull("account {$email} was not created");

        // The account signs in with the password it was given...
        $this->post(route('login'), ['email' => $email, 'password' => 'Demo-Passw0rd!'])
            ->assertRedirect();

        // ...and reaches its own area rather than being bounced.
        $this->actingAs($user)->get($path)->assertOk();

        auth()->logout();
    }
});

it('إعادة التشغيل لا تكرّر الحسابات ولا تغيّر كلمات المرور', function (): void {
    makeCohort(['status' => 'open', 'capacity' => 60]);

    $this->artisan('athar:demo-accounts', ['--password' => 'Demo-Passw0rd!'])->assertSuccessful();
    $before = User::query()->where('email', 'student@athar-demo.test')->value('password_hash');

    $this->artisan('athar:demo-accounts', ['--password' => 'Something-Else!'])->assertSuccessful();

    expect(User::query()->where('email', 'student@athar-demo.test')->count())->toBe(1)
        ->and(User::query()->where('email', 'student@athar-demo.test')->value('password_hash'))->toBe($before);
});

it('الإزالة تحذف الحسابات الأربعة', function (): void {
    makeCohort(['status' => 'open', 'capacity' => 60]);

    // BR-32 (D-117): the demo supervisor and system administrator go only when
    // another active holder of each role remains — SystemAdminIntegrityTest
    // pins the other side, where they are kept.
    makeAdmin();
    makeSystemAdmin();

    $this->artisan('athar:demo-accounts', ['--password' => 'Demo-Passw0rd!'])->assertSuccessful();
    $this->artisan('athar:demo-accounts', ['--remove' => true])->assertSuccessful();

    expect(User::query()->whereIn('email', [
        'admin@athar-demo.test',
        'supervisor@athar-demo.test',
        'trainer@athar-demo.test',
        'student@athar-demo.test',
    ])->count())->toBe(0);
});

it('D-117: مدير النظام التجريبي لا يلتحق بأي دفعة', function (): void {
    makeCohort(['status' => 'open', 'capacity' => 60]);

    $this->artisan('athar:demo-accounts', ['--password' => 'Demo-Passw0rd!'])->assertSuccessful();

    $sysadmin = User::query()->where('email', 'admin@athar-demo.test')->sole();

    expect($sysadmin->role->value)->toBe('system_admin')
        ->and(App\Models\Enrollment::query()->where('user_id', $sysadmin->id)->count())->toBe(0);
});

it('D-117: خطوتا ما بعد النشر — حساب admin@ القائم يبقى كما هو، ثم يصير مدير النظام بعد إنشاء المشرف', function (): void {
    makeCohort(['status' => 'open', 'capacity' => 60]);

    // The live host before this change: admin@ is the one administrator.
    $live = makeAdmin(['email' => 'admin@athar-demo.test']);

    // Step one reuses it without rewriting it, says so, and adds supervisor@.
    $this->artisan('athar:demo-accounts', ['--password' => 'Demo-Passw0rd!'])
        ->expectsOutputToContain('php artisan athar:change-role admin@athar-demo.test system_admin')
        ->assertSuccessful();

    expect($live->fresh()->role->value)->toBe('admin')
        ->and(User::query()->where('email', 'supervisor@athar-demo.test')->sole()->role->value)->toBe('admin');

    // Step two: allowed now that another active supervisor exists (BR-32).
    $this->artisan('athar:change-role', [
        'email' => 'admin@athar-demo.test',
        'role' => 'system_admin',
        '--reason' => 'D-117: the demo administrator becomes the system administrator.',
        '--force' => true,
    ])->assertSuccessful();

    expect($live->fresh()->role->value)->toBe('system_admin')
        ->and(App\Models\AuditLog::query()->where('action', 'user.role_changed')->where('entity_id', $live->id)->count())->toBe(1);
});
