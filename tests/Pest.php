<?php

declare(strict_types=1);

/**
 * Pest bootstrap: bindings, shared expectations and the fixture helpers that every
 * suite in this repository builds from.
 *
 * Design notes that matter, and that reviewers should not "simplify" away:
 *
 *  1. Time in this suite is always an explicit instant built with riyadhAt().
 *     Nothing here calls now(), Carbon::now(), time() or date(): the only clock is
 *     App\Services\Time\Clock, frozen with Clock::fake().
 *     (Constitution, Article 11 · PROJECT-CONTRACT.md §5.)
 *
 *  2. makeSessionAt() takes the two instants S and E that the test already knows and
 *     writes them into the row. The boundary suite therefore compares
 *     AttendanceWindow against instants it computed itself, never against instants
 *     re-derived by the code under test. An oracle that shares the bug it is meant
 *     to catch is not an oracle.
 *
 *  3. Enum values are compared as strings (->value). The contract fixes the backing
 *     values (PROJECT-CONTRACT.md §3); it does not fix the PHP case names, so the
 *     suite does not depend on them.
 *
 * @see CONSTITUTION.md Articles 11, 17, 20, 21, 26 · PROJECT-CONTRACT.md §14
 */

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Evaluation;
use App\Models\FinalProject;
use App\Models\JourneyStep;
use App\Models\Program;
use App\Models\ProjectSubmission;
use App\Models\Session;
use App\Models\Submission;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Models\Week;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Grading\ScoreCalculator;
use App\Services\Journey\JourneyEvaluator;
use App\Services\Time\Clock;
use App\Services\Time\RiyadhFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Bindings
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

/**
 * Latin numerals everywhere, and no thousands separator inside a year or an
 * identifier. This is the exact defect that shipped once already as "2,026".
 *
 * @see CONSTITUTION.md Article 15
 */
expect()->extend('toUseLatinNumerals', function () {
    expect((string) $this->value)
        ->not->toMatch('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u');

    expect((string) $this->value)
        ->not->toMatch('/\d,\d{3}/');

    return $this;
});

/**
 * A rendered screen carries a machine-readable state marker so the four mandatory
 * states (Article 17) can be asserted rather than eyeballed.
 */
expect()->extend('toBeScreenState', function (string $state) {
    expect($this->value)->toContain(sprintf('data-state="%s"', $state));

    return $this;
});

/*
|--------------------------------------------------------------------------
| Time helpers
|--------------------------------------------------------------------------
*/

/**
 * Build an instant from a wall-clock expression in Asia/Riyadh and return it in UTC,
 * which is how the database stores it.
 */
function riyadhAt(string $wallClock): CarbonImmutable
{
    return CarbonImmutable::parse($wallClock, 'Asia/Riyadh')->setTimezone('UTC');
}

/**
 * Freeze the only clock in the system at the given instant and hand it back, so a
 * test reads: $at = freezeAt($start->subMinutes(30));
 */
function freezeAt(CarbonImmutable $instant): CarbonImmutable
{
    Clock::fake($instant);

    return $instant;
}

/*
|--------------------------------------------------------------------------
| Fixture helpers
|--------------------------------------------------------------------------
*/

function makeProgram(array $attributes = []): Program
{
    // The slug is unique in the database, and makeCohort() creates a program per
    // call, so a fixed value collides the moment a test needs two cohorts. The
    // factory already generates a unique one; a test that cares passes its own.
    return Program::factory()->create($attributes + [
        'status' => 'published',
    ]);
}

function makeCohort(array $attributes = []): Cohort
{
    return Cohort::factory()->create($attributes + [
        'program_id' => makeProgram()->id,
        'status' => 'running',
        'pass_score' => 60,
        'min_attendance_rate' => 75,
        'capacity' => 30,
    ]);
}

function makeWeek(Cohort $cohort, int $index, array $attributes = []): Week
{
    return Week::factory()->create($attributes + [
        'cohort_id' => $cohort->id,
        'index' => $index,
    ]);
}

/**
 * Persist a session whose window is exactly [$start, $end] in real time.
 *
 * The two instants are handed in by the test; this helper only splits them into the
 * date / start_time / end_time columns the schema uses (PRD §7.3). A session whose
 * end falls on the following Riyadh day (23:00 to 01:00) is written the same way, so
 * the midnight-crossing case is exercised through the ordinary path.
 */
function makeSessionAt(CarbonImmutable $start, CarbonImmutable $end, array $attributes = []): Session
{
    $startRiyadh = $start->setTimezone('Asia/Riyadh');
    $endRiyadh = $end->setTimezone('Asia/Riyadh');

    return Session::factory()->create($attributes + [
        'cohort_id' => makeCohort()->id,
        'date' => $startRiyadh->toDateString(),
        'start_time' => $startRiyadh->format('H:i:s'),
        'end_time' => $endRiyadh->format('H:i:s'),
        'type' => 'training',
        'status' => 'scheduled',
    ]);
}

/**
 * The canonical session of PRD §9.9.3: 12 October 2026, 6:00 pm to 9:00 pm Riyadh.
 * Returns the row plus its S and E, so a boundary test never re-derives them.
 *
 * @return array{0: Session, 1: CarbonImmutable, 2: CarbonImmutable}
 */
function canonicalSession(array $attributes = []): array
{
    $start = riyadhAt('2026-10-12 18:00:00');
    $end = riyadhAt('2026-10-12 21:00:00');

    return [makeSessionAt($start, $end, $attributes), $start, $end];
}

function makeUser(string $role, array $attributes = []): User
{
    return User::factory()->create($attributes + [
        'role' => $role,
        'status' => 'active',
        'email_verified_at' => riyadhAt('2026-09-01 09:00:00'),
    ]);
}

function makeAdmin(array $attributes = []): User
{
    return makeUser('admin', $attributes);
}

/**
 * Set a known password without naming the column.
 *
 * PRD §7.1 calls the column `password_hash` while Laravel's Authenticatable defaults
 * to `password`; getAuthPasswordName() is the one place that knows which, so the
 * suite asks it instead of guessing.
 */
function withPassword(User $user, string $plain): User
{
    $user->forceFill([$user->getAuthPasswordName() => Hash::make($plain)])->save();

    return $user->fresh();
}

function makeTrainer(?Cohort $cohort = null, array $attributes = []): User
{
    $trainer = makeUser('trainer', $attributes);

    if ($cohort instanceof Cohort) {
        enroll($trainer, $cohort, 'trainer');
    }

    return $trainer;
}

function makeParticipant(?Cohort $cohort = null, array $attributes = []): User
{
    $participant = makeUser('participant', $attributes);

    if ($cohort instanceof Cohort) {
        enroll($participant, $cohort, 'participant');
    }

    return $participant;
}

function enroll(User $user, Cohort $cohort, string $roleInCohort = 'participant'): Enrollment
{
    return Enrollment::factory()->create([
        'cohort_id' => $cohort->id,
        'user_id' => $user->id,
        'role_in_cohort' => $roleInCohort,
        'status' => 'active',
        'enrolled_at' => riyadhAt('2026-09-15 10:00:00'),
    ]);
}

function makeAttendance(Session $session, User $user, string $status, array $attributes = []): Attendance
{
    return Attendance::factory()->create($attributes + [
        'session_id' => $session->id,
        'user_id' => $user->id,
        'status' => $status,
    ]);
}

function makeAssignment(Cohort $cohort, array $attributes = []): Assignment
{
    return Assignment::factory()->create($attributes + [
        'cohort_id' => $cohort->id,
        'max_score' => 10,
        'is_mandatory' => true,
        'allow_late' => false,
        'status' => 'published',
        'due_at' => riyadhAt('2026-10-20 23:59:00'),
    ]);
}

function makeSubmission(Assignment $assignment, User $user, array $attributes = []): Submission
{
    return Submission::factory()->create($attributes + [
        'assignment_id' => $assignment->id,
        'user_id' => $user->id,
        'version' => 1,
        'is_late' => false,
        'status' => 'submitted',
        'submitted_at' => riyadhAt('2026-10-19 20:00:00'),
    ]);
}

function makeFinalProject(Cohort $cohort, array $attributes = []): FinalProject
{
    return FinalProject::factory()->create($attributes + [
        'cohort_id' => $cohort->id,
        'is_unlocked' => false,
        'max_score' => 50,
        'due_at' => riyadhAt('2026-11-05 23:59:00'),
    ]);
}

function makeProjectSubmission(FinalProject $project, User $user, array $attributes = []): ProjectSubmission
{
    return ProjectSubmission::factory()->create($attributes + [
        'final_project_id' => $project->id,
        'user_id' => $user->id,
        'version' => 1,
        'is_late' => false,
        'submitted_at' => riyadhAt('2026-11-01 20:00:00'),
    ]);
}

/**
 * Record an evaluation. $entityType is 'assignment' or 'final_project'
 * (PROJECT-CONTRACT.md §3, EvaluationEntity).
 */
function makeEvaluation(string $entityType, string $entityId, User $user, float $score, array $attributes = []): Evaluation
{
    return Evaluation::factory()->create($attributes + [
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'user_id' => $user->id,
        'score' => $score,
        'max_score' => evaluationMaxScoreFor($entityType, $entityId),
        'feedback' => str_repeat('n', 24),
        'evaluated_by' => makeUser('trainer')->id,
        'evaluated_at' => riyadhAt('2026-10-21 12:00:00'),
    ]);
}

/**
 * The ceiling EvaluationRecorder would have snapshotted for this hand-in.
 *
 * `evaluations.entity_id` names a SUBMISSION, so the ceiling is two hops away:
 * submission -> assignment | final_project -> max_score. A fixture that invented
 * its own ceiling would let a revision test pass against a maximum no trainer
 * ever set (BR-12, PRD §9.15.4).
 */
function evaluationMaxScoreFor(string $entityType, string $entityId): float
{
    if ($entityType === 'final_project') {
        $projectId = ProjectSubmission::query()->whereKey($entityId)->value('final_project_id');
        $max = $projectId === null ? null : FinalProject::query()->whereKey($projectId)->value('max_score');
    } else {
        $assignmentId = Submission::query()->whereKey($entityId)->value('assignment_id');
        $max = $assignmentId === null ? null : Assignment::query()->whereKey($assignmentId)->value('max_score');
    }

    return is_numeric($max) ? (float) $max : 0.0;
}

/**
 * A private thread the given user takes part in.
 */
function makeThreadFor(User $user, Cohort $cohort, array $attributes = []): Thread
{
    $thread = Thread::factory()->create($attributes + [
        'cohort_id' => $cohort->id,
        'type' => 'trainer_dm',
        'created_by' => $user->id,
        'is_locked' => false,
    ]);

    ThreadParticipant::factory()->create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'last_read_at' => null,
        'is_muted' => false,
    ]);

    return $thread;
}

function issueCertificateFor(User $user, Cohort $cohort, array $attributes = []): Certificate
{
    return Certificate::factory()->create($attributes + [
        'user_id' => $user->id,
        'cohort_id' => $cohort->id,
        'issued_at' => riyadhAt('2026-11-20 10:00:00'),
        'revoked_at' => null,
    ]);
}

/**
 * A session that belongs to a given cohort, spanning exactly [$start, $end].
 */
function sessionInCohort(Cohort $cohort, CarbonImmutable $start, CarbonImmutable $end, array $attributes = []): Session
{
    return makeSessionAt($start, $end, $attributes + ['cohort_id' => $cohort->id]);
}

/**
 * A session of the given type inside a cohort, attended by $user with $status when
 * $status is not null. $dayOffset moves it forward from 5 October 2026.
 */
function sessionAttendedBy(Cohort $cohort, User $user, string $type, ?string $status, int $dayOffset = 0, ?string $weekId = null): Session
{
    $start = riyadhAt('2026-10-05 18:00:00')->addDays($dayOffset);

    $session = sessionInCohort($cohort, $start, $start->addHours(3), [
        'week_id' => $weekId,
        'type' => $type,
    ]);

    if ($status !== null) {
        makeAttendance($session, $user, $status);
    }

    return $session;
}

/**
 * The ten journey steps of PROJECT-CONTRACT.md §9, in order.
 *
 * The `unlock_rule` vocabulary is no longer a declared gap: PROJECT-CONTRACT.md §9.1
 * fixes it as a closed list, and JourneyEvaluator::RULE_* are its only spelling —
 * enrollment · intro_attendance · week_completion · project_submission ·
 * project_evaluation · closing_attendance · certificate_issued. For week_completion,
 * related_entity_type is 'week' and related_entity_id is the week row, which is what
 * makes steps 3 to 6 identical in code and distinct in data.
 *
 * @param  array<int, Week>  $weeks  keyed 1..4
 * @return array<int, JourneyStep>  keyed by step index 1..10
 */
function seedJourneySteps(Cohort $cohort, array $weeks): array
{
    $rules = [
        1 => ['enrollment', null, null],
        2 => ['intro_attendance', null, null],
        3 => ['week_completion', 'week', $weeks[1]->id],
        4 => ['week_completion', 'week', $weeks[2]->id],
        5 => ['week_completion', 'week', $weeks[3]->id],
        6 => ['week_completion', 'week', $weeks[4]->id],
        7 => ['project_submission', null, null],
        8 => ['project_evaluation', null, null],
        9 => ['closing_attendance', null, null],
        10 => ['certificate_issued', null, null],
    ];

    $steps = [];

    foreach ($rules as $index => [$rule, $entityType, $entityId]) {
        $steps[$index] = JourneyStep::factory()->create([
            'cohort_id' => $cohort->id,
            'index' => $index,
            'unlock_rule' => $rule,
            'related_entity_type' => $entityType,
            'related_entity_id' => $entityId,
        ]);
    }

    return $steps;
}

/**
 * Create $total sessions in the cohort and mark the first $attended of them with the
 * given attended status; the remainder are recorded absent.
 */
function attendSessions(Cohort $cohort, User $user, int $total, int $attended, string $attendedStatus = 'present'): void
{
    for ($i = 0; $i < $total; $i++) {
        sessionAttendedBy($cohort, $user, 'training', $i < $attended ? $attendedStatus : 'absent', $i);
    }
}

/**
 * Grade one assignment for one participant.
 */
function gradeAssignment(Cohort $cohort, User $participant, float $maxScore, float $awarded): void
{
    $assignment = makeAssignment($cohort, ['max_score' => $maxScore]);
    $submission = makeSubmission($assignment, $participant);

    makeEvaluation('assignment', $submission->id, $participant, $awarded);
}

/**
 * Award a final score of exactly $total out of 100, split across the assignment half
 * and, when needed, the project half.
 */
function awardFinalScore(Cohort $cohort, User $participant, float $total): void
{
    gradeAssignment($cohort, $participant, 50, min($total, 50.0));

    if ($total > 50.0) {
        $project = makeFinalProject($cohort, ['is_unlocked' => true]);
        $submission = makeProjectSubmission($project, $participant);

        makeEvaluation('final_project', $submission->id, $participant, $total - 50.0);
    }
}

/*
|--------------------------------------------------------------------------
| Service accessors — resolved from the container so bindings are honoured
|--------------------------------------------------------------------------
*/

function attendanceWindow(): AttendanceWindow
{
    return app(AttendanceWindow::class);
}

function scoreCalculator(): ScoreCalculator
{
    return app(ScoreCalculator::class);
}

function certificateEligibility(): CertificateEligibility
{
    return app(CertificateEligibility::class);
}

function journeyEvaluator(): JourneyEvaluator
{
    return app(JourneyEvaluator::class);
}

/**
 * App\Services\Time\RiyadhFormatter has instance methods, not static ones, so the
 * display suite asks the container for it exactly as a controller would.
 *
 * @see PROJECT-CONTRACT.md §5 · CONSTITUTION.md Article 11
 */
function riyadhFormatter(): RiyadhFormatter
{
    return app(RiyadhFormatter::class);
}

/*
|--------------------------------------------------------------------------
| Response helpers
|--------------------------------------------------------------------------
*/

/**
 * A request the server refused on a BUSINESS RULE.
 *
 * ONE WIRE SHAPE, and this helper now asserts exactly it:
 *
 *     HTTP 302 back to the previous screen, with a non-empty default error bag.
 *
 * That is the shape the platform actually produces, in all three of the places a
 * business rule can refuse, and it was chosen because they already agree:
 *
 *   1. FormRequest validation failure — Laravel's own behaviour for an HTML
 *      request (BR-12 score ceiling, BR-13 feedback length, BR-14 revision reason).
 *   2. A controller refusing on a rule it re-evaluated at the moment of the write,
 *      e.g. App\Http\Controllers\Admin\CertificateController::issue() —
 *      `back()->withErrors([...])` (BR-26).
 *   3. A service raising App\Exceptions\DomainException, whose render() redirects
 *      back with the localised message in the bag (BR-01…BR-09, BR-24).
 *
 * The old version accepted "302 with errors OR 422 OR 403 OR anything that is not
 * 2xx", which meant a rule test also passed when the endpoint 404'd, 405'd, threw a
 * 500 behind APP_DEBUG=false, or was refused by a policy for a completely different
 * reason. Roughly sixty tests asserted less than they read as.
 *
 * Two refusals are deliberately NOT this shape and are asserted with their own
 * matchers, never through here:
 *
 *   - an AUTHORISATION refusal is 403 (`assertForbidden()`), because the request
 *     was not merely wrong, it was not the caller's to make (Art. 22);
 *   - a JSON caller gets 422 from DomainException::render(); no endpoint in this
 *     platform is JSON-first, so the suite does not exercise that path.
 *
 * Callers must still assert that no row changed. A redirect proves the answer, not
 * the absence of a side effect.
 *
 * @see CONSTITUTION.md Articles 5, 7, 15, 17 · App\Exceptions\DomainException
 */
function assertRefused(TestResponse $response): TestResponse
{
    // Exactly 302, and exactly a populated error bag. Nothing else counts: a 403 is
    // an authorisation refusal (assertForbidden), and a 404, 405 or 500 is a defect
    // wearing a refusal's clothes.
    $response->assertStatus(302);
    $response->assertSessionHasErrors();

    return $response;
}

/**
 * A request the server accepted: 302 back with a clean error bag, or a rendered
 * 2xx. The mirror image of assertRefused(), so the two together cover the whole
 * space of answers a state-changing endpoint may give.
 */
function assertAccepted(TestResponse $response): TestResponse
{
    expect($response->status())->toBeIn([200, 201, 204, 302]);

    if ($response->status() === 302) {
        $response->assertSessionHasNoErrors();
    }

    return $response;
}

/*
|--------------------------------------------------------------------------
| Engine helpers — what SQLite cannot be asked to prove
|--------------------------------------------------------------------------
*/

/**
 * True when the suite is running on the in-memory SQLite connection rather than
 * the production engine.
 *
 * The environment is read directly, not through config(), so this can be
 * evaluated by a `->skip()` condition at collection time as well as inside a
 * booted test. phpunit.xml sets DB_CONNECTION and an outer environment variable
 * overrides it.
 *
 * @see phpunit.xml · config/database.php
 */
function usingSqlite(): bool
{
    $driver = getenv('DB_CONNECTION');

    if (! is_string($driver) || $driver === '') {
        $driver = (string) ($_ENV['DB_CONNECTION'] ?? 'mysql');
    }

    return $driver === 'sqlite';
}

/**
 * The reason attached to every test that is skipped on SQLite. One sentence, one
 * place, so the skip output says why rather than just "skipped".
 */
const MYSQL_ONLY_REASON = 'Schema-level guarantee: the CHECK constraint is added by '
    .'ALTER TABLE only on MySQL/MariaDB (see the migrations). Run with '
    .'DB_CONNECTION=mysql DB_DATABASE=athar_testing to assert it.';

/*
|--------------------------------------------------------------------------
| Screen helpers — Article 17, the four mandatory states
|--------------------------------------------------------------------------
*/

/**
 * The loading state is a Blade partial shaped like the content it replaces. It is a
 * real file, so it can be asserted to exist and to render without a request.
 *
 * @see CONSTITUTION.md Article 17
 */
function renderSkeleton(string $screen): string
{
    expect(View::exists('partials.skeletons.'.$screen))
        ->toBeTrue("Screen [{$screen}] has no loading skeleton at partials/skeletons/{$screen}.blade.php");

    return View::make('partials.skeletons.'.$screen)->render();
}
