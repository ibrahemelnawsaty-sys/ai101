<?php

/**
 * Renders every screen of the platform into public/sweep/, for
 * measure-responsive.mjs to open in a real browser at phone widths.
 *
 * WHY THE WHOLE HTTP STACK AND NOT THE CONTROLLERS DIRECTLY
 * The shell is what breaks on a phone: the rail, the header, the drawer, the
 * badges written by a view composer and the middleware that decides which rail
 * a role sees. Calling a controller's method skips every one of those. So each
 * page here goes through the kernel exactly as a browser's request would, and
 * the account is attached at RouteMatched — after the session has started and
 * before the auth middleware asks who this is.
 *
 * Runs on an in-memory SQLite database with the real seeders, writes nothing
 * outside public/sweep/ (which .gitignore excludes), and refuses to run
 * against anything but that database.
 *
 * Run: php tools/offline-checks/render-all-screens.php
 * Then: node tools/offline-checks/measure-responsive.mjs
 *       node tools/offline-checks/measure-rail-fit.mjs
 *
 * @see D-86, D-115
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

foreach ([['DB_CONNECTION', 'sqlite'], ['DB_DATABASE', ':memory:'], ['CACHE_STORE', 'array'],
    ['SESSION_DRIVER', 'array'], ['MAIL_MAILER', 'array'], ['QUEUE_CONNECTION', 'sync'],
    ['APP_ENV', 'local'], ['APP_URL', 'http://127.0.0.1']] as [$k, $v]) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

/** @var Illuminate\Foundation\Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
    fwrite(STDERR, "refusing: not the in-memory database\n");
    exit(2);
}

use App\Enums\CohortStatus;
use App\Models\Assignment;
use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

Mail::fake();
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true, '--seed' => true]);

Cohort::query()->update(['status' => CohortStatus::Running->value]);

$participant = User::query()
    ->where('role', 'participant')
    ->whereIn('id', Enrollment::query()->select('user_id'))
    ->orderBy('email')
    ->firstOrFail();
$participant->forceFill(['must_change_password' => false, 'temp_password_expires_at' => null])->save();

$trainer = User::query()->where('role', 'trainer')->orderBy('email')->firstOrFail();
$trainer->forceFill(['must_change_password' => false])->save();

$admin = User::query()->where('role', 'admin')->orderBy('email')->firstOrFail();
$admin->forceFill(['must_change_password' => false])->save();

$cohortId = (string) Enrollment::query()->where('user_id', $participant->getKey())->value('cohort_id');

// A coordinator (D-105). The seeders create none, so the role's own dashboard
// and rail (D-112) were never opened by this sweep.
$coordinator = User::factory()->create(['role' => 'coordinator', 'status' => 'active']);
Enrollment::factory()->create([
    'cohort_id' => $cohortId,
    'user_id' => $coordinator->getKey(),
    'role_in_cohort' => 'coordinator',
]);

// A trainee enrolled in two cohorts: the only rail that carries the cohort
// switcher (PRD §9.5.1, D-75), and so the tallest the rail's foot gets (D-115).
$twoCohorts = User::query()
    ->where('role', 'participant')
    ->whereKeyNot($participant->getKey())
    ->whereIn('id', Enrollment::query()->where('status', 'active')->where('role_in_cohort', 'participant')->select('user_id'))
    ->orderBy('email')
    ->firstOrFail();
$twoCohorts->forceFill(['must_change_password' => false, 'temp_password_expires_at' => null])->save();
Enrollment::factory()->create([
    'cohort_id' => Cohort::factory()->create(['program_id' => Cohort::query()->whereKey($cohortId)->value('program_id')])->getKey(),
    'user_id' => $twoCohorts->getKey(),
]);

// A certificate and a thread, so the two screens that need one are not empty.
if (! Certificate::query()->where('user_id', $participant->getKey())->exists()) {
    Certificate::factory()->create([
        'user_id' => $participant->getKey(),
        'cohort_id' => $cohortId,
        'revoked_at' => null,
    ]);
}

$threadId = Thread::query()
    ->whereIn('id', ThreadParticipant::query()->where('user_id', $participant->getKey())->select('thread_id'))
    ->value('id');

$assignmentId = Assignment::query()->value('id');
$userId = (string) $participant->getKey();

$out = $root.'/public/sweep';

if (! is_dir($out)) {
    mkdir($out, 0o755, true);
}

foreach (glob($out.'/*.html') ?: [] as $stale) {
    unlink($stale);
}

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

/** Render one URI as one account, straight through the kernel. */
$render = static function (string $name, string $uri, ?User $user) use ($kernel, $out): void {
    $listener = Event::listen(RouteMatched::class, static function () use ($user): void {
        if ($user instanceof User) {
            Auth::setUser($user);
        } else {
            Auth::logout();
        }
    });

    $response = $kernel->handle(Request::create($uri, 'GET'));
    $status = $response->getStatusCode();
    $html = (string) $response->getContent();

    Event::forget(RouteMatched::class);
    unset($listener);

    if ($status !== 200) {
        printf("%-28s %s  SKIPPED (%d)\n", $name, $uri, $status);

        return;
    }

    // The probe is served from a static directory, so absolute links to the
    // app's own host have to become relative or the browser leaves the server.
    $html = str_replace(['http://127.0.0.1/', 'http://localhost/', 'https://localhost/'], '/', $html);

    file_put_contents($out.'/'.$name.'.html', $html);
    printf("%-28s %s  %7d bytes\n", $name, $uri, strlen($html));
};

$screens = [
    // public
    ['public-home', '/', null],
    ['public-programs', '/programs', null],
    ['public-about', '/about', null],
    ['public-contact', '/contact', null],
    ['public-terms', '/terms', null],
    ['auth-login', '/login', null],
    ['auth-register', '/register', null],
    ['auth-forgot', '/forgot-password', null],

    // participant
    ['p-dashboard', '/dashboard', $participant],
    ['p-schedule', '/dashboard/schedule', $participant],
    ['p-live', '/dashboard/live', $participant],
    ['p-attendance', '/dashboard/attendance', $participant],
    ['p-assignments', '/dashboard/assignments', $participant],
    ['p-assignment', '/dashboard/assignments/'.$assignmentId, $participant],
    ['p-final-project', '/dashboard/final-project', $participant],
    ['p-grades', '/dashboard/grades', $participant],
    ['p-journey', '/dashboard/journey', $participant],
    ['p-resources', '/dashboard/resources', $participant],
    ['p-messages', '/dashboard/messages'.($threadId ? '?thread='.$threadId : ''), $participant],
    ['p-notifications', '/dashboard/notifications', $participant],
    ['p-card', '/dashboard/card', $participant],
    ['p-certificate', '/dashboard/certificate', $participant],
    ['p-profile', '/dashboard/profile', $participant],
    ['p-dashboard-two-cohorts', '/dashboard', $twoCohorts],

    // trainer
    ['t-dashboard', '/trainer/dashboard', $trainer],
    ['t-attendance', '/trainer/attendance', $trainer],
    ['t-sessions', '/trainer/sessions', $trainer],
    ['t-assignments', '/trainer/assignments', $trainer],
    ['t-submissions', '/trainer/submissions', $trainer],
    ['t-final-project', '/trainer/final-project', $trainer],
    ['t-participants', '/trainer/participants', $trainer],
    ['t-resources', '/trainer/resources', $trainer],
    ['t-reports', '/trainer/reports', $trainer],

    // coordinator
    ['c-dashboard', '/coordinator/dashboard', $coordinator],
    ['c-sessions', '/trainer/sessions', $coordinator],
    ['c-attendance', '/trainer/attendance', $coordinator],

    // admin
    ['a-dashboard', '/admin', $admin],
    ['a-users', '/admin/users', $admin],
    ['a-user', '/admin/users/'.$userId, $admin],
    ['a-user-create', '/admin/users/create', $admin],
    ['a-user-import', '/admin/users/import', $admin],
    ['a-cohorts', '/admin/cohorts', $admin],
    ['a-programs', '/admin/programs', $admin],
    ['a-registrations', '/admin/registrations', $admin],
    ['a-certificates', '/admin/certificates', $admin],
    ['a-reports', '/admin/reports', $admin],
    ['a-audit', '/admin/audit', $admin],
    ['a-landing', '/admin/landing', $admin],
    ['a-settings', '/admin/settings', $admin],
    ['a-broadcasts', '/admin/broadcasts', $admin],
    ['a-final-project', '/admin/final-project', $admin],
];

foreach ($screens as [$name, $uri, $user]) {
    try {
        $render($name, $uri, $user);
    } catch (Throwable $failure) {
        printf("%-28s %s  FAILED %s: %s\n", $name, $uri, $failure::class, $failure->getMessage());
    }
}

printf("\nwrote to %s\n", $out);
