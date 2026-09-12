<?php

declare(strict_types=1);

/**
 * `athar:make-user` creates the accounts a fresh deploy needs — admins and
 * trainers — and refuses trainees before asking anything.
 *
 * A trainee made there belonged to no cohort, and the command then told the
 * operator to "create the enrolment from the admin panel", a screen that does
 * not exist (D-63, D-69).
 *
 * @see D-63, D-69
 */

use App\Models\Enrollment;
use App\Models\Profile;
use App\Models\User;

it('D-69: الأمر يرفض دور المتدرّب قبل أن يسأل شيئًا ولا يكتب حسابًا', function (): void {
    $users = User::query()->count();
    $profiles = Profile::query()->count();

    $this->artisan('athar:make-user', ['--role' => 'participant'])
        ->expectsOutputToContain(route('admin.users.create'))
        ->expectsOutputToContain(route('admin.users.import'))
        ->assertFailed();

    expect(User::query()->count())->toBe($users)
        ->and(Profile::query()->count())->toBe($profiles);
});

it('D-69: الأمر يعرض المدير والمدرّب وحدهما حين لا يُذكر الدور', function (): void {
    $this->artisan('athar:make-user', [
        '--email' => 'coach@example.test',
        '--password' => 'Canary-Coach-1!',
        '--phone' => '0512345699',
        '--gender' => 'male',
        '--first-ar' => 'خالد', '--father-ar' => 'محمد', '--grandfather-ar' => 'علي', '--family-ar' => 'العتيبي',
        '--first-en' => 'Khalid', '--father-en' => 'Mohammed', '--grandfather-en' => 'Ali', '--family-en' => 'Alotaibi',
    ])
        ->expectsChoice('Role', 'trainer', ['admin', 'trainer'])
        ->assertSuccessful();

    expect(User::query()->where('email', 'coach@example.test')->sole()->role->value)->toBe('trainer')
        ->and(Enrollment::query()->count())->toBe(0);
});

it('D-69: دور مجهول يُسمّي أدوار الطرفية وحدها', function (): void {
    $this->artisan('athar:make-user', ['--role' => 'foo'])
        ->expectsOutputToContain('admin, trainer')
        ->doesntExpectOutputToContain('participant')
        ->assertFailed();
});
