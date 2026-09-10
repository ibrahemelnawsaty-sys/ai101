<?php

/**
 * Renders the REAL /admin/users/create page — the whole shell, not one card —
 * so the dropdown can be measured on the page the owner is actually looking at.
 *
 * The previous probe rendered a card on a bare document. That answered a
 * different question: a card in isolation has no sidebar, no sticky header and
 * no stacking context above it, and any one of those can change where a fixed
 * element lands or whether something paints over it.
 */

declare(strict_types=1);

$root = 'C:/Users/b.maher/Downloads/wesal/LARAVEL';

require $root.'/vendor/autoload.php';

foreach ([['DB_CONNECTION', 'sqlite'], ['DB_DATABASE', ':memory:'], ['CACHE_STORE', 'array'], ['SESSION_DRIVER', 'array']] as [$k, $v]) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
}

/** @var Illuminate\Foundation\Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);

use App\Models\Cohort;
use App\Models\Profile;
use App\Models\Program;
use App\Models\User;
use App\Services\Time\Clock;

$now = Clock::now();

$admin = User::query()->create([
    'email' => 'probe-admin@example.com',
    'password_hash' => 'Probe-Admin-1!',
    'role' => 'admin',
    'status' => 'active',
    'email_verified_at' => $now,
    'locale' => 'ar',
    'failed_login_count' => 0,
]);

Profile::query()->create([
    'user_id' => $admin->getKey(),
    'first_name_ar' => 'مدير', 'second_name_ar' => 'ال', 'third_name_ar' => 'نظام', 'last_name_ar' => 'أثر',
    'first_name_en' => 'Probe', 'second_name_en' => 'The', 'third_name_en' => 'Admin', 'last_name_en' => 'Athar',
    'phone' => '0512345600',
    'gender' => 'male',
]);

$program = Program::query()->create([
    'name_ar' => 'البرنامج التأسيسي في الذكاء الاصطناعي',
    'name_en' => 'Foundational AI Programme',
    'slug' => 'ai-101',
    'status' => 'published',
]);

Cohort::query()->create([
    'program_id' => $program->getKey(),
    'name' => 'الدفعة الأولى',
    'status' => 'open',
    'start_date' => $now->addDays(7)->format('Y-m-d'),
    'end_date' => $now->addDays(60)->format('Y-m-d'),
    'capacity' => 60,
    'seats_taken' => 0,
]);

Illuminate\Support\Facades\Auth::login($admin);

$request = Illuminate\Http\Request::create('/admin/users/create', 'GET');
$request->setUserResolver(static fn () => $admin);
app()->instance('request', $request);
Illuminate\Support\Facades\URL::setRequest($request);

// ShareErrorsFromSession runs in the HTTP stack, which this is not.
Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);

$html = app(App\Http\Controllers\Admin\UserController::class)->create($request)->render();

// Laravel writes absolute asset URLs against APP_URL. The probe server is on
// an ephemeral port, so they are made relative — otherwise the page loads no
// CSS and no JavaScript, and every question about Alpine gets a wrong answer
// that looks like a finding.
$html = str_replace(['http://localhost/', 'https://localhost/'], '/', $html);

// The page asks for /build/assets/… so it is served from public/, not public/build.
$out = $root.'/public/create-probe.html';
file_put_contents($out, $html);

printf("wrote %s (%d bytes)\n", $out, strlen($html));
printf("selects in markup   : %d\n", substr_count($html, 'ui-select__panel'));
printf("x-bind:style present: %s\n", str_contains($html, 'x-bind:style') ? 'YES — the old template is being served' : 'no');
