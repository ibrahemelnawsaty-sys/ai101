<?php

/**
 * Renders the four screens whose Alpine components were missing or started too
 * late (D-67) into public/, for measure-alpine-pages.mjs to load in a browser.
 *
 *   register-probe.html   the three-step registration wizard
 *   reset-probe.html      the reset-password screen, from a live recovery link
 *   schedule-probe.html   a participant's schedule, seeded cohort
 *   messages-probe.html   a participant's conversation, seeded cohort
 *
 * Why render and then load, rather than read the Blade: every one of these
 * failures was invisible in the source. The names were right; the ORDER of
 * module evaluation, or a missing definition, hid the content only once a
 * browser ran the built bundle. Only the built bundle answers that.
 *
 * Runs on an in-memory SQLite database with the real seeders. Writes nothing
 * outside public/*-probe.html, which .gitignore excludes.
 *
 * @see D-67
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

foreach ([['DB_CONNECTION', 'sqlite'], ['DB_DATABASE', ':memory:'], ['CACHE_STORE', 'array'],
    ['SESSION_DRIVER', 'array'], ['MAIL_MAILER', 'array'], ['QUEUE_CONNECTION', 'sync']] as [$k, $v]) {
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

Illuminate\Support\Facades\Mail::fake();
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true, '--seed' => true]);

use App\Enums\CohortStatus;
use App\Enums\EmailTokenType;
use App\Models\Cohort;
use App\Models\EmailToken;
use App\Models\Enrollment;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Services\Time\Clock;

$write = static function (string $name, string $html) use ($root): void {
    $html = str_replace(['http://localhost/', 'https://localhost/'], '/', $html);
    file_put_contents($root.'/public/'.$name, $html);
    printf("%-22s %7d bytes\n", $name, strlen($html));
};

$as = static function (?User $user, string $uri): Illuminate\Http\Request {
    $request = Illuminate\Http\Request::create($uri, 'GET');
    $request->setUserResolver(static fn () => $user);
    $request->setLaravelSession(app('session.store'));
    app()->instance('request', $request);
    Illuminate\Support\Facades\URL::setRequest($request);
    Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);

    if ($user !== null) {
        Illuminate\Support\Facades\Auth::login($user);
    } else {
        Illuminate\Support\Facades\Auth::logout();
    }

    return $request;
};

// 1 · registration — needs a cohort open on all three counts the controller
// checks (status, the landing switch, seats left, the deadline), or the screen
// shows "closed" and there is no wizard to measure.
Cohort::query()->update([
    'status' => CohortStatus::Open->value,
    'capacity' => 500,
    'registration_closes_at' => null,
]);
App\Models\LandingSetting::query()->update(['is_registration_open' => true]);
$as(null, '/register');
$write('register-probe.html', app(App\Http\Controllers\Auth\RegisterController::class)->create()->render());

// 2 · reset — a live token, issued the way the controller issues one.
$participant = User::query()
    ->where('role', 'participant')
    ->whereIn('id', Enrollment::query()->select('user_id'))
    ->orderBy('email')
    ->firstOrFail();

$plain = str_repeat('p', 64);
EmailToken::query()->create([
    'user_id' => $participant->getKey(),
    'token_hash' => hash('sha256', $plain),
    'type' => EmailTokenType::Reset->value,
    'expires_at' => Clock::now()->addMinutes(30),
    'used_at' => null,
]);
$as(null, '/reset-password/'.$plain);
$write('reset-probe.html', app(App\Http\Controllers\Auth\PasswordResetController::class)->reset($plain)->render());

// 3 · schedule, and 4 · messages — the seeded cohort, as a seeded participant.
Cohort::query()->update(['status' => CohortStatus::Running->value]);
$participant->forceFill(['must_change_password' => false])->save();

$request = $as($participant, '/dashboard/schedule');
$write('schedule-probe.html', app(App\Http\Controllers\Participant\ScheduleController::class)->index($request)->render());

$thread = Thread::query()->whereIn('id', ThreadParticipant::query()
    ->where('user_id', $participant->getKey())->select('thread_id'))->first();
$request = $as($participant, '/dashboard/messages'.($thread ? '?thread='.$thread->getKey() : ''));
$write('messages-probe.html', app(App\Http\Controllers\Participant\MessageController::class)->index($request)->render());

// 5 · the trainer's live roster — a session running NOW, polling every second,
// and the poll answer the endpoint gives once one participant has checked in.
$trainer = User::query()->where('role', 'trainer')->orderBy('email')->firstOrFail();
$session = App\Models\Session::query()
    ->where('cohort_id', (string) $participant->enrollments()->value('cohort_id'))
    ->orderBy('date')
    ->firstOrFail();
$window = app(App\Services\Attendance\AttendanceWindow::class);
Clock::fake($window->startsAt($session)->addMinutes(10));
config(['athar.attendance.roster_poll_seconds' => 1]);
App\Models\Attendance::query()->where('session_id', $session->getKey())->delete();

$request = $as($trainer, '/trainer/attendance?session='.$session->getKey());
$request->attributes->set(App\Http\Middleware\EnsureCohortScope::ATTRIBUTE, (string) $session->getAttribute('cohort_id'));
$write('roster-probe.html', app(App\Http\Controllers\Trainer\AttendanceController::class)->index($request)->render());

App\Models\Attendance::query()->create([
    'session_id' => $session->getKey(),
    'user_id' => $participant->getKey(),
    'status' => 'present',
    'check_in_at' => Clock::now()->subMinutes(3),
]);
$json = (string) app(App\Http\Controllers\Trainer\AttendanceController::class)->poll($session)->getContent();
file_put_contents($root.'/public/roster-probe.json', $json);
file_put_contents($root.'/public/roster-probe.meta', (string) $participant->getKey());
printf("%-22s %7d bytes\n", 'roster-probe.json', strlen($json));

// 6 · the printed certificate — a certificate issued to the seeded participant.
Clock::fake(null);
$certificate = App\Models\Certificate::query()->where('user_id', $participant->getKey())->first()
    ?? App\Models\Certificate::factory()->create([
        'user_id' => $participant->getKey(),
        'cohort_id' => (string) $participant->enrollments()->value('cohort_id'),
        'revoked_at' => null,
    ]);
$request = $as($participant, '/dashboard/certificate/print');
$write('certificate-probe.html', app(App\Http\Controllers\Participant\CertificateController::class)->print($request)->render());

printf("participant           %s\n", $participant->getAttribute('email'));
