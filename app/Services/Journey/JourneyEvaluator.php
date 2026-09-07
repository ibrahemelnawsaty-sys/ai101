<?php

declare(strict_types=1);

namespace App\Services\Journey;

use App\Enums\AssignmentStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\EvaluationEntity;
use App\Enums\JourneyStepStatus;
use App\Enums\SessionStatus;
use App\Enums\SessionType;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Evaluation;
use App\Models\FinalProject;
use App\Models\JourneyStep;
use App\Models\ProjectSubmission;
use App\Models\Session;
use App\Models\Submission;
use App\Models\User;
use App\Models\UserJourneyState;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use App\Support\AttendanceCounting;
use App\Support\ImpersonationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The ten journey steps, evaluated from real data only.
 *
 * BR-21: a step completes because the underlying rows exist - an attendance
 * record, a submission, an evaluation, a certificate. There is no manual
 * "mark as done" control anywhere in the platform, for anyone, and this class
 * deliberately exposes no method that would let a caller force a step complete.
 *
 * BR-20: step one is complete the moment the account is active and the
 * enrolment exists; nothing else is asked of the participant.
 *
 * BR-22: every query below is filtered by both the participant and the cohort
 * that owns the step, so one participant's data can never complete another's.
 *
 * CONTRACT GAP, declared rather than assumed silently: PROJECT-CONTRACT §9 and
 * PRD §9.7.1 fix the ten steps and their completion conditions, and PRD §7.6
 * gives journey_steps an `unlock_rule` column, but no document fixes the
 * vocabulary of that column. The seven constants below are that vocabulary,
 * mirrored by tests/Unit/Services/JourneyEvaluatorTest.php and by the seed
 * data. If the product owner rules otherwise, this class and the seed change
 * together and nothing else does.
 *
 * A rule this class does not recognise yields "not complete" - refusing is the
 * safe answer (CONSTITUTION art. 7).
 *
 * @see BR-20, BR-21, BR-22 · PRD §9.7.1 · PROJECT-CONTRACT §9
 */
final class JourneyEvaluator
{
    /** Step 1 - an active account plus an active enrolment (BR-20). */
    public const RULE_ENROLLMENT = 'enrollment';

    /** Step 2 - present or late at a session of type `intro`. */
    public const RULE_INTRO_ATTENDANCE = 'intro_attendance';

    /** Steps 3..6 - the week named by related_entity_id. */
    public const RULE_WEEK_COMPLETION = 'week_completion';

    /** Step 7 - a final-project submission exists. */
    public const RULE_PROJECT_SUBMISSION = 'project_submission';

    /** Step 8 - the final project carries a recorded evaluation. */
    public const RULE_PROJECT_EVALUATION = 'project_evaluation';

    /** Step 9 - present or late at a session of type `closing`. */
    public const RULE_CLOSING_ATTENDANCE = 'closing_attendance';

    /** Step 10 - a certificate has been issued and not revoked. */
    public const RULE_CERTIFICATE_ISSUED = 'certificate_issued';

    /** The value of `related_entity_type` that steps 3..6 carry. */
    public const ENTITY_WEEK = 'week';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * BR-21 - is this step complete for this participant, right now, according
     * to the rows that actually exist?
     */
    public function isStepComplete(User $user, JourneyStep $step): bool
    {
        $cohortId = $this->stringAttribute($step, 'cohort_id');
        $userId = $this->keyOf($user);

        if ($cohortId === null || $userId === null) {
            return false;
        }

        // BR-22 - no enrolment, no journey. Every rule is gated by this, not
        // only step one, so a stray row can never complete a stranger's step.
        if (! $this->isEnrolled($userId, $cohortId)) {
            return false;
        }

        return match ($this->stringAttribute($step, 'unlock_rule')) {
            self::RULE_ENROLLMENT => $this->isAccountActive($user),
            self::RULE_INTRO_ATTENDANCE => $this->attendedSessionOfType($userId, $cohortId, SessionType::Intro),
            self::RULE_WEEK_COMPLETION => $this->weekIsComplete($userId, $cohortId, $this->relatedWeekId($step)),
            self::RULE_PROJECT_SUBMISSION => $this->hasProjectSubmission($userId, $cohortId),
            self::RULE_PROJECT_EVALUATION => $this->hasProjectEvaluation($userId, $cohortId),
            self::RULE_CLOSING_ATTENDANCE => $this->attendedSessionOfType($userId, $cohortId, SessionType::Closing),
            self::RULE_CERTIFICATE_ISSUED => $this->hasCertificate($userId, $cohortId),
            default => false,
        };
    }

    /**
     * The cohort's steps in their fixed order (PROJECT-CONTRACT §9).
     *
     * @return Collection<int, JourneyStep>
     */
    public function steps(Cohort $cohort): Collection
    {
        /** @var Collection<int, JourneyStep> $steps */
        $steps = JourneyStep::query()
            ->where('cohort_id', $cohort->getKey())
            ->orderBy('index')
            ->get();

        return $steps;
    }

    /**
     * Step id => status. Exactly one step is `current`: the first one that is
     * not complete. Everything after it is `locked`, and a step that is
     * genuinely complete reads `completed` even when an earlier one is not -
     * the timeline reflects the data, it does not rewrite it.
     *
     * @return array<string, JourneyStepStatus>
     */
    public function statuses(User $user, Cohort $cohort): array
    {
        return $this->overview($user, $cohort)['statuses'];
    }

    /**
     * How many of the cohort's steps this participant has actually completed.
     */
    public function completedCount(User $user, Cohort $cohort): int
    {
        return $this->overview($user, $cohort)['completed'];
    }

    public function totalSteps(Cohort $cohort): int
    {
        return JourneyStep::query()->where('cohort_id', $cohort->getKey())->count();
    }

    /**
     * The percentage behind the progress bar, one decimal place, never above
     * one hundred. Latin digits are the caller's concern (RiyadhFormatter).
     */
    public function progressPercent(User $user, Cohort $cohort): float
    {
        return $this->overview($user, $cohort)['percent'];
    }

    /**
     * Everything the journey screen needs, computed in a single pass.
     *
     * @return array{
     *     steps: Collection<int, JourneyStep>,
     *     statuses: array<string, JourneyStepStatus>,
     *     completed: int,
     *     total: int,
     *     percent: float,
     *     current: JourneyStep|null
     * }
     */
    public function overview(User $user, Cohort $cohort): array
    {
        $steps = $this->steps($cohort);

        /** @var array<string, JourneyStepStatus> $statuses */
        $statuses = [];
        $completed = 0;
        $current = null;

        foreach ($steps as $step) {
            $id = (string) $step->getKey();

            if ($this->isStepComplete($user, $step)) {
                $statuses[$id] = JourneyStepStatus::Completed;
                $completed++;

                continue;
            }

            if ($current === null) {
                $statuses[$id] = JourneyStepStatus::Current;
                $current = $step;

                continue;
            }

            $statuses[$id] = JourneyStepStatus::Locked;
        }

        $total = $steps->count();

        return [
            'steps' => $steps,
            'statuses' => $statuses,
            'completed' => $completed,
            'total' => $total,
            'percent' => $total === 0 ? 0.0 : round(($completed / $total) * 100, 1),
            'current' => $current,
        ];
    }

    /**
     * Persist the derived statuses into user_journey_states.
     *
     * Idempotent: the pair (user_id, journey_step_id) is unique, so re-running
     * the sync updates the same ten rows instead of adding more. Only an actual
     * transition is written, and only a transition is audited.
     *
     * BR-34 - during an account preview nothing is written at all: the preview
     * must leave no trace whatsoever on the previewed account.
     *
     * @return array<string, JourneyStepStatus>
     */
    public function sync(User $user, Cohort $cohort): array
    {
        $statuses = $this->statuses($user, $cohort);
        $userId = $this->keyOf($user);

        if ($statuses === [] || $userId === null || ImpersonationContext::isActive()) {
            return $statuses;
        }

        $now = Clock::now();

        $existing = UserJourneyState::query()
            ->where('user_id', $userId)
            ->whereIn('journey_step_id', array_keys($statuses))
            ->get()
            ->keyBy(static fn (UserJourneyState $state): string => (string) $state->getAttribute('journey_step_id'));

        foreach ($statuses as $stepId => $status) {
            $row = $existing->get($stepId);

            if (! $row instanceof UserJourneyState) {
                $fresh = new UserJourneyState();
                $fresh->setAttribute('user_id', $userId);
                $fresh->setAttribute('journey_step_id', $stepId);
                $fresh->setAttribute('status', $status);
                $fresh->setAttribute('completed_at', $status === JourneyStepStatus::Completed ? $now : null);
                $fresh->save();

                continue;
            }

            if ($this->statusValue($row->getAttribute('status')) === $status->value) {
                continue;
            }

            $before = $this->audit->snapshot($row, self::AUDITED_COLUMNS);

            $row->setAttribute('status', $status);
            $row->setAttribute(
                'completed_at',
                $status === JourneyStepStatus::Completed
                    ? ($row->getAttribute('completed_at') ?? $now)
                    : null,
            );

            $this->audit->log(
                action: AuditLogger::JOURNEY_STEP_CHANGED,
                entity: $row,
                before: $before,
                after: $this->audit->snapshot($row, self::AUDITED_COLUMNS),
                actor: null,
            );

            $row->save();
        }

        return $statuses;
    }

    /** @var list<string> */
    private const AUDITED_COLUMNS = ['id', 'user_id', 'journey_step_id', 'status', 'completed_at'];

    // ------------------------------------------------------------- the rules

    /**
     * BR-20 - the account has been activated and the enrolment exists.
     */
    private function isAccountActive(User $user): bool
    {
        return $user->isActive();
    }

    private function isEnrolled(string $userId, string $cohortId): bool
    {
        return Enrollment::query()
            ->where('cohort_id', $cohortId)
            ->where('user_id', $userId)
            ->where('role_in_cohort', EnrollmentRole::Participant->value)
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Completed->value])
            ->exists();
    }

    /**
     * Present or late at a session of the given type. An excused absence is not
     * attendance for the purposes of the journey (PROJECT-CONTRACT §9).
     */
    private function attendedSessionOfType(string $userId, string $cohortId, SessionType $type): bool
    {
        return Attendance::query()
            ->where('user_id', $userId)
            ->whereIn('status', AttendanceCounting::physicallyPresentValues())
            ->whereIn(
                'session_id',
                $this->sessionQuery($cohortId)->where('type', $type->value)->select('id'),
            )
            ->exists();
    }

    /**
     * A week step needs both halves: every one of the week's sessions attended,
     * and every mandatory assignment of that week submitted. A week with no
     * session at all is not complete - there is nothing to have attended, and
     * inventing a pass would hand out a step nobody earned.
     */
    private function weekIsComplete(string $userId, string $cohortId, ?string $weekId): bool
    {
        if ($weekId === null) {
            return false;
        }

        $sessionIds = $this->sessionQuery($cohortId)
            ->where('week_id', $weekId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($sessionIds === []) {
            return false;
        }

        $attended = Attendance::query()
            ->where('user_id', $userId)
            ->whereIn('session_id', $sessionIds)
            ->whereIn('status', AttendanceCounting::physicallyPresentValues())
            ->distinct()
            ->count('session_id');

        if ($attended < count($sessionIds)) {
            return false;
        }

        $mandatoryIds = Assignment::query()
            ->where('cohort_id', $cohortId)
            ->where('week_id', $weekId)
            ->where('is_mandatory', true)
            ->where('status', AssignmentStatus::Published->value)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($mandatoryIds === []) {
            return true;
        }

        $submitted = Submission::query()
            ->where('user_id', $userId)
            ->whereIn('assignment_id', $mandatoryIds)
            ->distinct()
            ->count('assignment_id');

        return $submitted >= count($mandatoryIds);
    }

    private function hasProjectSubmission(string $userId, string $cohortId): bool
    {
        return ProjectSubmission::query()
            ->where('user_id', $userId)
            ->whereIn('final_project_id', $this->finalProjectQuery($cohortId))
            ->exists();
    }

    private function hasProjectEvaluation(string $userId, string $cohortId): bool
    {
        $submissionIds = ProjectSubmission::query()
            ->where('user_id', $userId)
            ->whereIn('final_project_id', $this->finalProjectQuery($cohortId))
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($submissionIds === []) {
            return false;
        }

        return Evaluation::query()
            ->where('user_id', $userId)
            ->where('entity_type', EvaluationEntity::FinalProject->value)
            ->whereIn('entity_id', $submissionIds)
            ->exists();
    }

    /**
     * A revoked certificate does not complete the step: the public verification
     * page says the certificate is cancelled, and the journey says the same.
     */
    private function hasCertificate(string $userId, string $cohortId): bool
    {
        return Certificate::query()
            ->where('user_id', $userId)
            ->where('cohort_id', $cohortId)
            ->whereNull('revoked_at')
            ->exists();
    }

    // ----------------------------------------------------------- small tools

    /**
     * Non-cancelled sessions of one cohort.
     *
     * @return Builder<Session>
     */
    private function sessionQuery(string $cohortId): Builder
    {
        return Session::query()
            ->where('cohort_id', $cohortId)
            ->where('status', '!=', SessionStatus::Cancelled->value);
    }

    /**
     * @return Builder<FinalProject>
     */
    private function finalProjectQuery(string $cohortId): Builder
    {
        return FinalProject::query()->where('cohort_id', $cohortId)->select('id');
    }

    private function relatedWeekId(JourneyStep $step): ?string
    {
        if ($this->stringAttribute($step, 'related_entity_type') !== self::ENTITY_WEEK) {
            return null;
        }

        return $this->stringAttribute($step, 'related_entity_id');
    }

    private function stringAttribute(JourneyStep $step, string $column): ?string
    {
        $value = $step->getAttribute($column);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function statusValue(mixed $status): ?string
    {
        if ($status instanceof JourneyStepStatus) {
            return $status->value;
        }

        return is_string($status) ? $status : null;
    }

    private function keyOf(User $user): ?string
    {
        $key = $user->getKey();

        return $key === null ? null : (string) $key;
    }
}
