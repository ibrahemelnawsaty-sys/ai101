<?php

declare(strict_types=1);

use App\Models\User;

/**
 * The command exists so a reviewer can walk the platform on a fresh deploy.
 * That is only true if the accounts it makes can actually sign in and land on
 * their own screens, so this asserts the outcome, not the rows.
 */
it('ينشئ ثلاثة حسابات يمكنها الدخول والوصول لشاشاتها', function (): void {
    makeCohort(['status' => 'open', 'capacity' => 60]);

    $this->artisan('athar:demo-accounts', ['--password' => 'Demo-Passw0rd!'])
        ->assertSuccessful();

    $expected = [
        'admin@athar-demo.test' => '/admin',
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

it('الإزالة تحذف الحسابات الثلاثة', function (): void {
    makeCohort(['status' => 'open', 'capacity' => 60]);

    $this->artisan('athar:demo-accounts', ['--password' => 'Demo-Passw0rd!'])->assertSuccessful();
    $this->artisan('athar:demo-accounts', ['--remove' => true])->assertSuccessful();

    expect(User::query()->whereIn('email', [
        'admin@athar-demo.test',
        'trainer@athar-demo.test',
        'student@athar-demo.test',
    ])->count())->toBe(0);
});
