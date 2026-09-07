<?php

declare(strict_types=1);

/**
 * G9 — every protected route refuses every wrong role, on every request.
 *
 * The fourth test in this file is the one that keeps the gate honest: it walks the
 * router and fails if any protected route has been added without an entry in the
 * matrix below. A route nobody remembered to cover is exactly the route that ships
 * unguarded — and until this run the matrix held 24 of the 80 `admin.*`,
 * `trainer.*` and `participant.*` leaves, so 56 of them were never asked what they
 * answer a trainee.
 *
 * Leaf names come from routes/web.php, which is the authority on the leaves:
 * PROJECT-CONTRACT.md §10 fixes the `trainer.*` and `admin.*` prefixes and their
 * middleware but enumerates only two admin leaves. The trainer rows carry
 * `cohort` as a QUERY parameter, not a path one: the trainer area takes its cohort
 * from `?cohort=`, read by EnsureCohortScope (BR-23).
 *
 * @see BR-22, BR-23, BR-28, BR-33, BR-35 · PRD §4.2, §4.3, §12.2
 * @see CONSTITUTION.md Articles 5, 22, 26 (G9)
 */

use App\Models\Enrollment;
use App\Models\LandingSetting;
use App\Models\Program;
use App\Models\Resource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

uses()->group('authz');

beforeEach(function () {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->program = Program::query()->findOrFail($this->cohort->program_id);

    $this->admin = makeAdmin();
    $this->trainer = makeTrainer($this->cohort);
    $this->participant = makeParticipant($this->cohort);

    $this->enrollment = Enrollment::query()
        ->where('cohort_id', $this->cohort->id)
        ->where('user_id', $this->participant->id)
        ->sole();

    $this->assignment = makeAssignment($this->cohort, ['max_score' => 10]);
    $this->submission = makeSubmission($this->assignment, $this->participant);
    $this->evaluation = makeEvaluation('assignment', $this->submission->id, $this->participant, 8.0);
    $this->finalProject = makeFinalProject($this->cohort);
    $this->projectSubmission = makeProjectSubmission($this->finalProject, $this->participant);

    $start = riyadhAt('2026-10-12 18:00:00');
    $this->session = sessionInCohort($this->cohort, $start, $start->addHours(3));
    $this->attendance = makeAttendance($this->session, $this->participant, 'present');

    // A link resource, so the archive endpoints need no file on the disk.
    $this->resource = Resource::factory()->create([
        'cohort_id' => $this->cohort->id,
        'type' => 'link',
        'external_url' => 'https://example.test/reading-list',
    ]);

    $this->certificate = issueCertificateFor($this->participant, $this->cohort);

    $this->landing = LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'faq' => [['key' => 'faq-one', 'question' => 'Q', 'answer' => 'A']],
    ]);

    $this->actors = [
        'admin' => $this->admin,
        'trainer' => $this->trainer,
        'participant' => $this->participant,
    ];
});

/**
 * Forget the per-IP rate-limit counters between two rows of the matrix.
 *
 * The walker makes some two hundred requests from one address inside a single
 * second, which no caller ever does; without this, `throttle:password` - shared
 * by admin.users.resetPassword and admin.users.resendVerification and keyed by
 * IP - answers 429 to the fourth of them and hides the 403 that PRD §4.3
 * requires. Throttling is real and is asserted where it belongs; here it is
 * cross-talk between unrelated rows.
 */
function resetRequestCounters(): void
{
    Cache::store()->flush();
}

/**
 * The authorization matrix.
 *
 * Each entry: route name => [http verb, route parameters, roles allowed].
 * Roles omitted from the third element must receive 403.
 *
 * @return array<string, array{0: string, 1: array<int|string, mixed>, 2: list<string>}>
 */
function authorizationMatrix(object $test): array
{
    $cohort = ['cohort' => $test->cohort->id];

    return [
        // ---------------------------------------------------------- participant
        'participant.card' => ['get', [], ['participant']],
        'participant.card.download' => ['get', [], ['participant']],
        'participant.journey' => ['get', [], ['participant']],
        'finalProject' => ['get', [], ['participant']],
        'grades' => ['get', [], ['participant']],
        'certificate' => ['get', [], ['participant']],
        'attendance.checkIn' => ['post', [$test->session], ['participant']],
        'attendance.checkOut' => ['post', [$test->session], ['participant']],
        'assignments.submit' => ['post', [$test->assignment], ['participant']],

        // -------------------------------------------- shared authenticated area
        'dashboard' => ['get', [], ['participant', 'trainer', 'admin']],
        'schedule' => ['get', [], ['participant', 'trainer', 'admin']],
        'attendance.index' => ['get', [], ['participant', 'trainer', 'admin']],
        'live' => ['get', [], ['participant', 'trainer', 'admin']],
        'assignments.index' => ['get', [], ['participant', 'trainer', 'admin']],
        'resources.index' => ['get', [], ['participant', 'trainer', 'admin']],
        'messages.index' => ['get', [], ['participant', 'trainer', 'admin']],
        'profile' => ['get', [], ['participant', 'trainer', 'admin']],
        'notifications' => ['get', [], ['participant', 'trainer', 'admin']],

        // -------------------------------------------------------------- trainer
        'trainer.participants' => ['get', $cohort, ['trainer', 'admin']],
        'trainer.participants.export' => ['get', $cohort, ['trainer', 'admin']],

        'trainer.attendance' => ['get', $cohort, ['trainer', 'admin']],
        'trainer.attendance.export' => ['get', $cohort, ['trainer', 'admin']],
        'trainer.attendance.poll' => ['get', $cohort + ['session' => $test->session->id], ['trainer', 'admin']],
        'trainer.attendance.update' => ['patch', $cohort + ['attendance' => $test->attendance->id], ['trainer', 'admin']],
        'trainer.attendance.bulk' => ['post', $cohort + ['session' => $test->session->id], ['trainer', 'admin']],

        'trainer.sessions' => ['get', $cohort, ['trainer', 'admin']],
        'trainer.sessions.store' => ['post', $cohort, ['trainer', 'admin']],
        'trainer.sessions.update' => ['patch', $cohort + ['session' => $test->session->id], ['trainer', 'admin']],
        'trainer.sessions.cancel' => ['post', $cohort + ['session' => $test->session->id], ['trainer', 'admin']],

        'trainer.assignments' => ['get', $cohort, ['trainer', 'admin']],
        'trainer.assignments.store' => ['post', $cohort, ['trainer', 'admin']],
        'trainer.assignments.update' => ['patch', $cohort + ['assignment' => $test->assignment->id], ['trainer', 'admin']],

        'trainer.submissions' => ['get', $cohort, ['trainer', 'admin']],
        'trainer.submissions.export' => ['get', $cohort, ['trainer', 'admin']],
        // Recording and revising a mark are the two rows of the PRD §4.2 matrix
        // where the administrator's column reads «لا» and the trainer's «نعم»:
        // «رصد الدرجات وكتابة الملاحظات» and «تعديل درجة بعد رصدها». The mark is
        // the trainer's professional judgement and nobody else's, so an admin is
        // refused here exactly as a participant is.
        'trainer.submissions.grade' => ['post', $cohort + ['submission' => $test->submission->id], ['trainer']],
        'trainer.submissions.revise' => ['patch', $cohort + ['evaluation' => $test->evaluation->id], ['trainer']],
        'trainer.submissions.remind' => ['post', $cohort + ['assignment' => $test->assignment->id], ['trainer', 'admin']],
        'trainer.submissions.bulkDownload' => ['get', $cohort + ['assignment' => $test->assignment->id], ['trainer', 'admin']],

        'trainer.finalProject' => ['get', $cohort, ['trainer', 'admin']],
        'trainer.finalProject.unlock' => ['put', $cohort + ['project' => $test->finalProject->id], ['trainer', 'admin']],
        // Same PRD §4.2 row: unlocking the project is an admin's to do, marking it is not.
        'trainer.finalProject.grade' => ['post', $cohort + ['submission' => $test->projectSubmission->id], ['trainer']],

        'trainer.resources' => ['get', $cohort, ['trainer', 'admin']],
        'trainer.resources.store' => ['post', $cohort, ['trainer', 'admin']],
        'trainer.resources.archive' => ['delete', $cohort + ['resource' => $test->resource->id], ['trainer', 'admin']],

        'trainer.reports' => ['get', $cohort, ['trainer', 'admin']],
        'trainer.reports.export' => ['get', $cohort, ['trainer', 'admin']],

        // ---------------------------------------------------------------- admin
        'admin.dashboard' => ['get', [], ['admin']],
        'admin.audit.index' => ['get', [], ['admin']],
        'admin.audit.export' => ['get', [], ['admin']],

        'admin.programs.index' => ['get', [], ['admin']],
        'admin.programs.store' => ['post', [], ['admin']],
        'admin.programs.update' => ['patch', [$test->program], ['admin']],
        'admin.programs.archive' => ['put', [$test->program], ['admin']],

        'admin.cohorts.index' => ['get', [], ['admin']],
        'admin.cohorts.store' => ['post', [], ['admin']],
        'admin.cohorts.update' => ['patch', [$test->cohort], ['admin']],
        'admin.cohorts.trainers.attach' => ['post', [$test->cohort], ['admin']],
        'admin.cohorts.trainers.detach' => ['delete', [$test->cohort, $test->trainer], ['admin']],

        'admin.users.index' => ['get', [], ['admin']],
        'admin.users.create' => ['get', [], ['admin']],
        'admin.users.export' => ['get', [], ['admin']],
        'admin.users.store' => ['post', [], ['admin']],
        'admin.users.show' => ['get', [$test->participant], ['admin']],
        'admin.users.update' => ['patch', [$test->participant], ['admin']],
        'admin.users.role' => ['put', [$test->participant], ['admin']],
        'admin.users.status' => ['patch', [$test->participant], ['admin']],
        'admin.users.resetPassword' => ['post', [$test->participant], ['admin']],
        'admin.users.resendVerification' => ['post', [$test->participant], ['admin']],
        'admin.users.logoutEverywhere' => ['post', [$test->participant], ['admin']],
        'admin.users.destroy' => ['delete', [$test->participant], ['admin']],
        // Starting a preview creates a row and rewrites the session; it is a POST.
        'admin.users.preview' => ['post', [$test->participant], ['admin']],

        'admin.registrations.index' => ['get', [], ['admin']],
        'admin.registrations.export' => ['get', [], ['admin']],
        'admin.registrations.approve' => ['put', [$test->enrollment], ['admin']],
        'admin.registrations.reject' => ['put', [$test->enrollment], ['admin']],

        'admin.certificates.index' => ['get', [], ['admin']],
        'admin.certificates.export' => ['get', [], ['admin']],
        'admin.certificates.issue' => ['post', [], ['admin']],
        'admin.certificates.issueBulk' => ['post', [], ['admin']],
        'admin.certificates.reissue' => ['post', [$test->certificate], ['admin']],
        'admin.certificates.revoke' => ['delete', [$test->certificate], ['admin']],
        'admin.certificates.override' => ['post', [$test->participant], ['admin']],

        'admin.landing.edit' => ['get', [], ['admin']],
        'admin.landing.update' => ['put', [], ['admin']],
        'admin.landing.faq.store' => ['post', [], ['admin']],
        'admin.landing.faq.update' => ['put', ['entry' => 'faq-one'], ['admin']],
        'admin.landing.faq.destroy' => ['delete', ['entry' => 'faq-one'], ['admin']],

        'admin.reports.index' => ['get', [], ['admin']],
        'admin.reports.export' => ['get', [], ['admin']],

        'admin.settings.edit' => ['get', [], ['admin']],
        'admin.settings.update' => ['put', [], ['admin']],
        'admin.settings.notifications' => ['put', [], ['admin']],
        'admin.settings.template' => ['get', ['template' => 'welcome'], ['admin']],
    ];
}

it('كل مسار محمي يرفض كل دور غير مصرّح له برمز 403', function () {
    $failures = [];

    foreach (authorizationMatrix($this) as $name => [$verb, $parameters, $allowed]) {
        foreach ($this->actors as $role => $actor) {
            if (in_array($role, $allowed, true)) {
                continue;
            }

            $response = $this->actingAs($actor)->{$verb}(route($name, $parameters));

            if ($response->status() !== 403) {
                $failures[] = sprintf('%s as %s returned %d, expected 403', $name, $role, $response->status());
            }

            $this->flushSession();
            resetRequestCounters();
        }
    }

    expect($failures)->toBe([]);
});

it('كل مسار محمي يرفض الزائر غير المسجل', function () {
    $failures = [];

    foreach (authorizationMatrix($this) as $name => [$verb, $parameters]) {
        $response = $this->{$verb}(route($name, $parameters));

        // A guest is redirected to the login screen, never served the resource.
        if (! in_array($response->status(), [302, 401, 403], true)) {
            $failures[] = sprintf('%s as guest returned %d', $name, $response->status());
        }

        $this->flushSession();
        resetRequestCounters();
    }

    expect($failures)->toBe([]);
});

it('كل دور مصرّح له يصل فعلًا إلى مساره — الضبط المضاد', function () {
    // Without this, a route that returns 403 to everyone would pass the test above.
    $failures = [];

    foreach (authorizationMatrix($this) as $name => [$verb, $parameters, $allowed]) {
        foreach ($allowed as $role) {
            $response = $this->actingAs($this->actors[$role])->{$verb}(route($name, $parameters));

            if ($response->status() === 403) {
                $failures[] = sprintf('%s as %s was forbidden, expected access', $name, $role);
            }

            $this->flushSession();
            resetRequestCounters();
        }
    }

    expect($failures)->toBe([]);
});

it('لا يوجد مسار محمي بلا سطر في مصفوفة التفويض', function () {
    $covered = array_keys(authorizationMatrix($this));

    $protected = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter()
        ->filter(fn (string $name): bool => str_starts_with($name, 'admin.')
            || str_starts_with($name, 'trainer.')
            || str_starts_with($name, 'participant.')
        )
        ->reject(fn (string $name): bool => in_array($name, $covered, true))
        // The impersonation stop route is deliberately reachable by whoever is
        // currently previewing, so it is covered in ImpersonationRulesTest instead.
        ->reject(fn (string $name): bool => $name === 'admin.impersonation.stop')
        ->values()
        ->all();

    expect($protected)->toBe([]);
});

it('كل مسار مغيّر للحالة محمي بوسيط المصادقة', function () {
    $unguarded = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => (bool) array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']))
        ->reject(fn ($route): bool => in_array($route->getName(), [
            // The only state-changing routes a guest may reach, by design, named
            // as routes/web.php actually names them. This list used to say
            // `login`, `register`, `password.request` and `password.reset` —
            // the names of the GET PAGES — so not one of the POSTs below was
            // ever excused, and the whole assertion was failing on them rather
            // than on anything real.
            //   signing in and signing up                    PRD §9.2, §9.3.1
            'login.store', 'register.store',
            //   password recovery, both legs                        PRD §9.3.3
            'password.email', 'password.update',
            //   "send the activation link again", offered on the sign-in screen
            //   to the visitor who cannot sign in precisely because they never
            //   confirmed their address                             PRD §9.3.1
            'verification.send',
            //   the waiting list a visitor joins when the cohort is full or
            //   registration has closed                             PRD §9.1.3
            'waitlist.store',
        ], true))
        ->reject(fn ($route): bool => in_array('auth', $route->gatherMiddleware(), true))
        ->map(fn ($route): string => (string) $route->getName().' '.$route->uri())
        ->values()
        ->all();

    expect($unguarded)->toBe([]);
});

it('المسارات العامة تبقى مفتوحة للزوار', function () {
    foreach (['home', 'terms', 'privacy'] as $name) {
        $this->get(route($name))->assertOk();
    }
});


