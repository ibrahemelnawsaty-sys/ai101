<?php

declare(strict_types=1);

namespace App\Services\Grading;

use App\Enums\AssignmentStatus;
use App\Enums\EvaluationEntity;
use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\Evaluation;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\Submission;
use App\Models\User;

/**
 * The single source of truth for a participant's marks.
 *
 *   assignments 50  +  final project 50  =  100
 *
 * The trainer distributes the fifty assignment marks across the assignments as
 * they see fit. If the distributed maxima do not add up to fifty the trainer is
 * warned in their dashboard but never blocked (PRD §9.15.1), so the sum here is
 * capped rather than rescaled — rescaling would silently rewrite marks the
 * trainer actually awarded.
 *
 * @see BR-11, BR-12 · PRD §9.15.1 · CONTRACT §7
 */
final class ScoreCalculator
{
    public const ASSIGNMENTS_TOTAL = 50;   // BR-11

    public const PROJECT_TOTAL = 50;       // BR-11

    public const GRAND_TOTAL = 100;        // BR-11

    private const PRECISION = 2;

    /**
     * Sum of the participant's assignment marks in this cohort, capped at 50.
     */
    public function assignmentsScore(User $user, Cohort $cohort): float
    {
        $total = 0.0;

        foreach ($this->assignmentScores($user, $cohort) as $score) {
            $total += $score;
        }

        return $this->clamp($total, self::ASSIGNMENTS_TOTAL);
    }

    /**
     * The final project mark, capped at 50.
     */
    public function projectScore(User $user, Cohort $cohort): float
    {
        $submissionIds = $this->projectSubmissionIds($user, $cohort);

        if ($submissionIds === []) {
            return 0.0;
        }

        $score = Evaluation::query()
            ->where('user_id', $user->getKey())
            ->where('entity_type', EvaluationEntity::FinalProject)
            ->whereIn('entity_id', $submissionIds)
            ->orderByDesc('evaluated_at')
            ->value('score');

        return $score === null ? 0.0 : $this->clamp((float) $score, self::PROJECT_TOTAL);
    }

    /**
     * The overall mark out of 100.
     */
    public function finalScore(User $user, Cohort $cohort): float
    {
        $total = $this->assignmentsScore($user, $cohort) + $this->projectScore($user, $cohort);

        return $this->clamp($total, self::GRAND_TOTAL);
    }

    /**
     * The overall mark of a whole list of people, in a fixed handful of queries.
     *
     * Exactly what finalScore() gives for each of them — same published assignments, same «last
     * evaluation of an assignment wins», same latest project mark, same caps and rounding, and an
     * evaluation counts for a person only when it names one of THEIR hand-ins. It exists so a
     * report that averages a whole cohort does not ask finalScore() sixty times (about seven
     * queries each, art. 19), while the rule stays in this class (art. 6). The tests hold the
     * two side by side.
     *
     * @param  array<int, string>  $userIds
     * @return array<string, float> person id => mark out of 100; every id is present
     */
    public function finalScores(array $userIds, Cohort $cohort): array
    {
        $ids = array_values(array_unique(array_map(static fn (mixed $id): string => (string) $id, $userIds)));

        $scores = [];

        foreach ($ids as $id) {
            $scores[$id] = 0.0;
        }

        if ($ids === []) {
            return $scores;
        }

        $assignments = $this->assignmentTotalsFor($ids, $cohort);
        $projects = $this->projectMarksFor($ids, $cohort);

        foreach ($ids as $id) {
            $assignmentsScore = $this->clamp($assignments[$id] ?? 0.0, self::ASSIGNMENTS_TOTAL);
            $projectScore = array_key_exists($id, $projects)
                ? $this->clamp($projects[$id], self::PROJECT_TOTAL)
                : 0.0;

            $scores[$id] = $this->clamp($assignmentsScore + $projectScore, self::GRAND_TOTAL);
        }

        return $scores;
    }

    /**
     * Whether the participant reaches the cohort's pass mark.
     */
    public function passes(User $user, Cohort $cohort): bool
    {
        return $this->finalScore($user, $cohort) >= $this->passScore($cohort);
    }

    /**
     * The cohort's pass mark, defaulting to the documented 60 out of 100.
     */
    public function passScore(Cohort $cohort): float
    {
        $value = $cohort->getAttribute('pass_score');

        return is_numeric($value) ? (float) $value : 60.0;
    }

    /**
     * Sum of max_score across the cohort's published assignments. Used by the
     * trainer dashboard warning when it does not equal fifty (PRD §9.15.1).
     */
    public function assignmentsMaxTotal(Cohort $cohort): float
    {
        $sum = Assignment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', AssignmentStatus::Published)
            ->sum('max_score');

        return round((float) $sum, self::PRECISION);
    }

    /**
     * BR-11 — true when the trainer's distribution adds up to exactly fifty.
     */
    public function assignmentsTotalIsBalanced(Cohort $cohort): bool
    {
        return abs($this->assignmentsMaxTotal($cohort) - self::ASSIGNMENTS_TOTAL) < 0.005;
    }

    /**
     * Everything the grades screen needs, computed in one place.
     *
     * @return array{
     *     assignments: float,
     *     assignments_total: int,
     *     project: float,
     *     project_total: int,
     *     final: float,
     *     grand_total: int,
     *     pass_score: float,
     *     passes: bool
     * }
     */
    public function breakdown(User $user, Cohort $cohort): array
    {
        $assignments = $this->assignmentsScore($user, $cohort);
        $project = $this->projectScore($user, $cohort);
        $final = $this->clamp($assignments + $project, self::GRAND_TOTAL);
        $passScore = $this->passScore($cohort);

        return [
            'assignments' => $assignments,
            'assignments_total' => self::ASSIGNMENTS_TOTAL,
            'project' => $project,
            'project_total' => self::PROJECT_TOTAL,
            'final' => $final,
            'grand_total' => self::GRAND_TOTAL,
            'pass_score' => $passScore,
            'passes' => $final >= $passScore,
        ];
    }

    /**
     * The most recent mark per assignment. A resubmission keeps its earlier
     * versions (BR-19), so only the latest evaluation of an assignment counts.
     *
     * @return array<string, float> assignment id => score
     */
    public function assignmentScores(User $user, Cohort $cohort): array
    {
        $assignmentIds = Assignment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', AssignmentStatus::Published)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($assignmentIds === []) {
            return [];
        }

        $submissions = Submission::query()
            ->where('user_id', $user->getKey())
            ->whereIn('assignment_id', $assignmentIds)
            ->get(['id', 'assignment_id']);

        if ($submissions->isEmpty()) {
            return [];
        }

        /** @var array<string, string> $assignmentBySubmission */
        $assignmentBySubmission = [];

        foreach ($submissions as $submission) {
            $assignmentBySubmission[(string) $submission->getAttribute('id')] =
                (string) $submission->getAttribute('assignment_id');
        }

        $evaluations = Evaluation::query()
            ->where('user_id', $user->getKey())
            ->where('entity_type', EvaluationEntity::Assignment)
            ->whereIn('entity_id', array_keys($assignmentBySubmission))
            ->orderBy('evaluated_at')
            ->get(['entity_id', 'score', 'evaluated_at']);

        /** @var array<string, float> $scores */
        $scores = [];

        foreach ($evaluations as $evaluation) {
            $submissionId = (string) $evaluation->getAttribute('entity_id');
            $assignmentId = $assignmentBySubmission[$submissionId] ?? null;

            if ($assignmentId === null) {
                continue;
            }

            // Ordered ascending by evaluation time, so the last write wins.
            $scores[$assignmentId] = max(0.0, (float) $evaluation->getAttribute('score'));
        }

        return $scores;
    }

    /**
     * assignmentScores() summed, for many people at once (uncapped — the caller clamps).
     *
     * @param  list<string>  $userIds
     * @return array<string, float> person id => sum of their latest mark per published assignment
     */
    private function assignmentTotalsFor(array $userIds, Cohort $cohort): array
    {
        $assignmentIds = Assignment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', AssignmentStatus::Published)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($assignmentIds === []) {
            return [];
        }

        $submissions = Submission::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('assignment_id', $assignmentIds)
            ->get(['id', 'user_id', 'assignment_id']);

        if ($submissions->isEmpty()) {
            return [];
        }

        /** @var array<string, array{user: string, assignment: string}> $owner */
        $owner = [];

        foreach ($submissions as $submission) {
            $owner[(string) $submission->getAttribute('id')] = [
                'user' => (string) $submission->getAttribute('user_id'),
                'assignment' => (string) $submission->getAttribute('assignment_id'),
            ];
        }

        $evaluations = Evaluation::query()
            ->whereIn('user_id', $userIds)
            ->where('entity_type', EvaluationEntity::Assignment)
            ->whereIn('entity_id', array_keys($owner))
            ->orderBy('evaluated_at')
            ->get(['entity_id', 'user_id', 'score', 'evaluated_at']);

        /** @var array<string, array<string, float>> $latest person id => assignment id => latest mark */
        $latest = [];

        foreach ($evaluations as $evaluation) {
            $hand = $owner[(string) $evaluation->getAttribute('entity_id')] ?? null;

            // A mark counts for a person only on one of THEIR hand-ins.
            if ($hand === null || $hand['user'] !== (string) $evaluation->getAttribute('user_id')) {
                continue;
            }

            // Ascending by evaluation time, so the last write wins.
            $latest[$hand['user']][$hand['assignment']] = max(0.0, (float) $evaluation->getAttribute('score'));
        }

        $totals = [];

        foreach ($latest as $userId => $marks) {
            $totals[$userId] = array_sum($marks);
        }

        return $totals;
    }

    /**
     * projectScore() before its cap, for many people at once.
     *
     * @param  list<string>  $userIds
     * @return array<string, float> person id => latest final-project mark; absent when none
     */
    private function projectMarksFor(array $userIds, Cohort $cohort): array
    {
        $finalProjectId = FinalProject::query()
            ->where('cohort_id', $cohort->getKey())
            ->value('id');

        if ($finalProjectId === null) {
            return [];
        }

        $submissions = ProjectSubmission::query()
            ->where('final_project_id', $finalProjectId)
            ->whereIn('user_id', $userIds)
            ->get(['id', 'user_id']);

        if ($submissions->isEmpty()) {
            return [];
        }

        /** @var array<string, string> $ownerOf submission id => person id */
        $ownerOf = [];

        foreach ($submissions as $submission) {
            $ownerOf[(string) $submission->getAttribute('id')] = (string) $submission->getAttribute('user_id');
        }

        $evaluations = Evaluation::query()
            ->whereIn('user_id', $userIds)
            ->where('entity_type', EvaluationEntity::FinalProject)
            ->whereIn('entity_id', array_keys($ownerOf))
            ->orderByDesc('evaluated_at')
            ->get(['entity_id', 'user_id', 'score', 'evaluated_at']);

        $marks = [];

        foreach ($evaluations as $evaluation) {
            $userId = $ownerOf[(string) $evaluation->getAttribute('entity_id')] ?? null;

            if ($userId === null || $userId !== (string) $evaluation->getAttribute('user_id')) {
                continue;
            }

            // Newest first: the first mark seen for a person is their latest.
            if (! array_key_exists($userId, $marks)) {
                $marks[$userId] = (float) $evaluation->getAttribute('score');
            }
        }

        return $marks;
    }

    /**
     * @return list<string>
     */
    private function projectSubmissionIds(User $user, Cohort $cohort): array
    {
        $finalProjectId = FinalProject::query()
            ->where('cohort_id', $cohort->getKey())
            ->value('id');

        if ($finalProjectId === null) {
            return [];
        }

        // array_values: Collection::all() types as array<int, string>, so the
        // list<string> this method promises has to be restored explicitly.
        return array_values(ProjectSubmission::query()
            ->where('final_project_id', $finalProjectId)
            ->where('user_id', $user->getKey())
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all());
    }

    /**
     * BR-12 — a mark can never be negative nor exceed its ceiling.
     */
    private function clamp(float $value, float $ceiling): float
    {
        return round(max(0.0, min($value, $ceiling)), self::PRECISION);
    }
}
