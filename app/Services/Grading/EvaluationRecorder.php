<?php

declare(strict_types=1);

namespace App\Services\Grading;

use App\Enums\EvaluationEntity;
use App\Enums\SubmissionStatus;
use App\Exceptions\GradingException;
use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Evaluation;
use App\Models\FinalProject;
use App\Models\Notification;
use App\Models\ProjectSubmission;
use App\Models\Submission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Records and revises marks.
 *
 *  · BR-12 a mark outside [0, max_score] is refused on the server — never
 *          clamped, because silently lowering a trainer's input would hide the
 *          mistake instead of surfacing it (PRD §9.15.4 acceptance criteria)
 *  · BR-13 feedback is mandatory and at least ten characters long
 *  · BR-14 revising a recorded mark requires a reason, is written to the audit
 *          trail, and notifies the participant
 *
 * @see BR-11, BR-12, BR-13, BR-14 · PRD §9.15
 */
final class EvaluationRecorder
{
    public const MIN_FEEDBACK_LENGTH = 10;          // BR-13

    public const MIN_REVISION_REASON_LENGTH = 10;   // BR-14

    /** Notification matrix slugs of PRD §9.16.1, stored in notifications.type. */
    public const TYPE_RECORDED = 'grade_recorded';

    public const TYPE_REVISED = 'grade_revised';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ScoreCalculator $scores,
    ) {}

    /**
     * Record the first mark for an assignment submission.
     *
     * @throws GradingException
     */
    public function record(User $evaluator, Submission $submission, float $score, string $feedback): Evaluation
    {
        $assignmentId = $submission->getAttribute('assignment_id');

        $maxScore = Assignment::query()
            ->whereKey($assignmentId)
            ->value('max_score');

        if ($maxScore === null) {
            throw GradingException::maxScoreMissing();
        }

        $cohortId = Assignment::query()->whereKey($assignmentId)->value('cohort_id');

        return $this->store(
            evaluator: $evaluator,
            submission: $submission,
            entityType: EvaluationEntity::Assignment,
            score: $score,
            maxScore: (float) $maxScore,
            feedback: $feedback,
            cohortId: is_string($cohortId) ? $cohortId : null,
        );
    }

    /**
     * Record the first mark for a final-project submission.
     *
     * @throws GradingException
     */
    public function recordProject(User $evaluator, ProjectSubmission $submission, float $score, string $feedback): Evaluation
    {
        $finalProjectId = $submission->getAttribute('final_project_id');

        $finalProject = FinalProject::query()->whereKey($finalProjectId)->first();

        if ($finalProject === null) {
            throw GradingException::submissionNotFound();
        }

        $maxScore = $finalProject->getAttribute('max_score');

        if ($maxScore === null) {
            throw GradingException::maxScoreMissing();
        }

        $cohortId = $finalProject->getAttribute('cohort_id');

        return $this->store(
            evaluator: $evaluator,
            submission: $submission,
            entityType: EvaluationEntity::FinalProject,
            score: $score,
            maxScore: (float) $maxScore,
            feedback: $feedback,
            cohortId: is_string($cohortId) ? $cohortId : null,
        );
    }

    /**
     * BR-14 — revise a mark that was already recorded.
     *
     * @throws GradingException
     */
    public function revise(
        User $evaluator,
        Evaluation $evaluation,
        float $score,
        string $feedback,
        string $reason,
    ): Evaluation {
        $trimmedReason = trim($reason);

        if (mb_strlen($trimmedReason) < self::MIN_REVISION_REASON_LENGTH) {
            $failure = GradingException::revisionReasonRequired(self::MIN_REVISION_REASON_LENGTH);
            $this->audit->reject(AuditLogger::EVALUATION_REVISED, $evaluation, $failure, $evaluator);

            throw $failure;
        }

        $trimmedFeedback = $this->guardFeedback($evaluation, $evaluator, $feedback);
        $maxScore = $this->maxScoreFor($evaluation);
        $this->guardScore($evaluation, $evaluator, $score, $maxScore);

        $participantId = (string) $evaluation->getAttribute('user_id');
        $at = Clock::now();

        $updated = DB::transaction(function () use (
            $evaluation,
            $evaluator,
            $score,
            $maxScore,
            $trimmedFeedback,
            $trimmedReason,
            $at,
        ): Evaluation {
            $before = $this->audit->snapshot($evaluation, $this->auditedColumns());

            $evaluation->setAttribute('score', $score);
            // Snapshot of the entity's maximum at grading time. The column is
            // NOT NULL and a CHECK constraint reads it (PRD §7.7); keeping the
            // snapshot also stops a later edit to an assignment's max_score from
            // retroactively changing what an already-recorded mark was measured
            // against.
            $evaluation->setAttribute('max_score', $maxScore);
            $evaluation->setAttribute('feedback', $trimmedFeedback);
            $evaluation->setAttribute('revision_reason', $trimmedReason);
            $evaluation->setAttribute('evaluated_by', $evaluator->getKey());
            $evaluation->setAttribute('evaluated_at', $at);

            $this->audit->log(
                action: AuditLogger::EVALUATION_REVISED,
                entity: $evaluation,
                before: $before,
                after: $this->audit->snapshot($evaluation, $this->auditedColumns()),
                actor: $evaluator,
            );

            $evaluation->save();

            return $evaluation;
        });

        $cohort = $this->cohortForEvaluation($updated);

        if ($cohort !== null) {
            $this->refreshFinalScore($participantId, $cohort);
        }

        $this->notifyParticipant($updated, self::TYPE_REVISED, $maxScore, $trimmedReason, $at);

        return $updated;
    }

    /**
     * The evaluation already recorded for a submission, if any.
     */
    public function existingFor(Model $submission, EvaluationEntity $entityType): ?Evaluation
    {
        return Evaluation::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $submission->getKey())
            ->first();
    }

    /**
     * @throws GradingException
     */
    private function store(
        User $evaluator,
        Model $submission,
        EvaluationEntity $entityType,
        float $score,
        float $maxScore,
        string $feedback,
        ?string $cohortId,
    ): Evaluation {
        if ($this->existingFor($submission, $entityType) !== null) {
            $failure = GradingException::alreadyEvaluated();
            $this->audit->reject(AuditLogger::EVALUATION_RECORDED, $submission, $failure, $evaluator);

            throw $failure;
        }

        $trimmedFeedback = $this->guardFeedback($submission, $evaluator, $feedback);
        $this->guardScore($submission, $evaluator, $score, $maxScore);

        $participantId = (string) $submission->getAttribute('user_id');
        $at = Clock::now();

        $evaluation = DB::transaction(function () use (
            $evaluator,
            $submission,
            $entityType,
            $score,
            $maxScore,
            $trimmedFeedback,
            $participantId,
            $at,
        ): Evaluation {
            $evaluation = new Evaluation();
            $evaluation->setAttribute($evaluation->getKeyName(), (string) Str::uuid());
            $evaluation->setAttribute('entity_type', $entityType);
            $evaluation->setAttribute('entity_id', $submission->getKey());
            $evaluation->setAttribute('user_id', $participantId);
            $evaluation->setAttribute('score', $score);
            // BR-12, PRD 9.15.4: `evaluations.max_score` is NOT NULL and the
            // CHECK constraint reads it, so the ceiling in force at grading time
            // is snapshotted here rather than re-derived later.
            $evaluation->setAttribute('max_score', $maxScore);
            $evaluation->setAttribute('feedback', $trimmedFeedback);
            $evaluation->setAttribute('evaluated_by', $evaluator->getKey());
            $evaluation->setAttribute('evaluated_at', $at);
            $evaluation->setAttribute('revision_reason', null);

            $this->audit->log(
                action: AuditLogger::EVALUATION_RECORDED,
                entity: $evaluation,
                before: null,
                after: $this->audit->snapshot($evaluation, $this->auditedColumns()),
                actor: $evaluator,
            );

            $evaluation->save();

            if ($submission instanceof Submission) {
                $submission->setAttribute('status', SubmissionStatus::Graded);
                $submission->save();
            }

            return $evaluation;
        });

        if ($cohortId !== null) {
            $cohort = Cohort::query()->whereKey($cohortId)->first();

            if ($cohort instanceof Cohort) {
                $this->refreshFinalScore($participantId, $cohort);
            }
        }

        $this->notifyParticipant($evaluation, self::TYPE_RECORDED, $maxScore, null, $at);

        return $evaluation;
    }

    /**
     * BR-13.
     *
     * @throws GradingException
     */
    private function guardFeedback(Model $entity, User $evaluator, string $feedback): string
    {
        $trimmed = trim($feedback);

        if (mb_strlen($trimmed) < self::MIN_FEEDBACK_LENGTH) {
            $failure = GradingException::feedbackTooShort(self::MIN_FEEDBACK_LENGTH);
            $this->audit->reject(AuditLogger::EVALUATION_RECORDED, $entity, $failure, $evaluator);

            throw $failure;
        }

        return $trimmed;
    }

    /**
     * BR-12.
     *
     * @throws GradingException
     */
    private function guardScore(Model $entity, User $evaluator, float $score, float $maxScore): void
    {
        if ($score >= 0.0 && $score <= $maxScore) {
            return;
        }

        $failure = GradingException::scoreOutOfRange($maxScore);
        $this->audit->reject(AuditLogger::EVALUATION_RECORDED, $entity, $failure, $evaluator);

        throw $failure;
    }

    /**
     * @throws GradingException
     */
    private function maxScoreFor(Evaluation $evaluation): float
    {
        // The snapshot taken at grading time wins: re-deriving it would let a
        // trainer widen an assignment after the fact and silently change what an
        // already-recorded mark was measured against.
        $snapshot = $evaluation->getAttribute('max_score');

        if ($snapshot !== null) {
            return (float) $snapshot;
        }

        $entityType = $evaluation->getAttribute('entity_type');
        $entityType = $entityType instanceof EvaluationEntity
            ? $entityType
            : (is_string($entityType) ? EvaluationEntity::tryFrom($entityType) : null);

        if ($entityType === EvaluationEntity::Assignment) {
            $assignmentId = Submission::query()
                ->whereKey($evaluation->getAttribute('entity_id'))
                ->value('assignment_id');

            $maxScore = Assignment::query()->whereKey($assignmentId)->value('max_score');
        } elseif ($entityType === EvaluationEntity::FinalProject) {
            $finalProjectId = ProjectSubmission::query()
                ->whereKey($evaluation->getAttribute('entity_id'))
                ->value('final_project_id');

            $maxScore = FinalProject::query()->whereKey($finalProjectId)->value('max_score');
        } else {
            throw GradingException::submissionNotFound();
        }

        if ($maxScore === null) {
            throw GradingException::maxScoreMissing();
        }

        return (float) $maxScore;
    }

    private function cohortForEvaluation(Evaluation $evaluation): ?Cohort
    {
        $entityType = $evaluation->getAttribute('entity_type');
        $entityType = $entityType instanceof EvaluationEntity
            ? $entityType
            : (is_string($entityType) ? EvaluationEntity::tryFrom($entityType) : null);

        if ($entityType === EvaluationEntity::Assignment) {
            $assignmentId = Submission::query()
                ->whereKey($evaluation->getAttribute('entity_id'))
                ->value('assignment_id');

            $cohortId = Assignment::query()->whereKey($assignmentId)->value('cohort_id');
        } elseif ($entityType === EvaluationEntity::FinalProject) {
            $finalProjectId = ProjectSubmission::query()
                ->whereKey($evaluation->getAttribute('entity_id'))
                ->value('final_project_id');

            $cohortId = FinalProject::query()->whereKey($finalProjectId)->value('cohort_id');
        } else {
            return null;
        }

        if (! is_string($cohortId) || $cohortId === '') {
            return null;
        }

        $cohort = Cohort::query()->whereKey($cohortId)->first();

        return $cohort instanceof Cohort ? $cohort : null;
    }

    /**
     * The overall mark is derived, never entered — it is refreshed whenever a
     * component of it changes (PRD §9.15.2).
     */
    private function refreshFinalScore(string $participantId, Cohort $cohort): void
    {
        $user = User::query()->whereKey($participantId)->first();

        if (! $user instanceof User) {
            return;
        }

        Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('user_id', $participantId)
            ->update([
                'final_score' => $this->scores->finalScore($user, $cohort),
                'updated_at' => Clock::now()->format('Y-m-d H:i:s'),
            ]);
    }

    /**
     * BR-14 - the participant is told, in the platform, whenever a mark of
     * theirs is recorded or revised.
     *
     * The type slug and the copy both come from the notification matrix of
     * PRD §9.16.1, so a preference the participant set applies to it.
     */
    private function notifyParticipant(
        Evaluation $evaluation,
        string $type,
        float $maxScore,
        ?string $reason,
        DateTimeInterface $at,
    ): void {
        $participantId = (string) $evaluation->getAttribute('user_id');

        $replacements = [
            'item' => $this->itemTitle($evaluation),
            'score' => $this->number((float) $evaluation->getAttribute('score')),
            'max' => $this->number($maxScore),
            'reason' => $reason ?? '',
        ];

        $notification = new Notification();
        $notification->setAttribute('user_id', $participantId);
        $notification->setAttribute('type', $type);
        $notification->setAttribute('title', (string) __('notifications.types.'.$type.'.title', $replacements));
        $notification->setAttribute('body', (string) __('notifications.types.'.$type.'.body', $replacements));
        $notification->setAttribute('link', Route::has('grades') ? route('grades') : null);
        $notification->setAttribute('is_read', false);
        $notification->setAttribute('read_at', null);
        $notification->setAttribute('channel', 'in_app');
        $notification->setAttribute('created_at', $at);
        $notification->setAttribute('updated_at', $at);
        $notification->save();
    }

    /**
     * The name of the graded item, for the notification copy.
     */
    private function itemTitle(Evaluation $evaluation): string
    {
        $entityType = $this->entityTypeOf($evaluation);

        if ($entityType === EvaluationEntity::Assignment) {
            $assignmentId = Submission::query()
                ->whereKey($evaluation->getAttribute('entity_id'))
                ->value('assignment_id');

            $title = Assignment::query()->whereKey($assignmentId)->value('title');
        } elseif ($entityType === EvaluationEntity::FinalProject) {
            $finalProjectId = ProjectSubmission::query()
                ->whereKey($evaluation->getAttribute('entity_id'))
                ->value('final_project_id');

            $title = FinalProject::query()->whereKey($finalProjectId)->value('title');
        } else {
            $title = null;
        }

        return is_string($title) && $title !== '' ? $title : '';
    }

    private function entityTypeOf(Evaluation $evaluation): ?EvaluationEntity
    {
        $entityType = $evaluation->getAttribute('entity_type');

        if ($entityType instanceof EvaluationEntity) {
            return $entityType;
        }

        return is_string($entityType) ? EvaluationEntity::tryFrom($entityType) : null;
    }

    /**
     * Latin digits, at most two decimals, no trailing zeros: «8.5», not «8.50».
     */
    private function number(float $value): string
    {
        $formatted = number_format(round($value, 2), 2, '.', '');

        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @return list<string>
     */
    private function auditedColumns(): array
    {
        return [
            'id',
            'entity_type',
            'entity_id',
            'user_id',
            'score',
            'max_score',
            'feedback',
            'evaluated_by',
            'evaluated_at',
            'revision_reason',
        ];
    }
}
