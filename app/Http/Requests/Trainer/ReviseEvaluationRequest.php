<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Enums\EvaluationEntity;
use App\Models\Assignment;
use App\Models\Evaluation;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\Submission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amending a grade that has already been recorded (BR-14).
 *
 * Three things are required together: the new score, the feedback that still
 * has to stand on its own (BR-13), and a written reason for the change. The
 * amendment is audited and the trainee is notified; neither is optional.
 *
 * The ceiling comes from the graded item, never from the payload (BR-12).
 *
 * @see BR-12, BR-13, BR-14, BR-23 · PRD §9.15.2 · CONSTITUTION Art. 8
 */
final class ReviseEvaluationRequest extends FormRequest
{
    public const MIN_FEEDBACK_LENGTH = 10;

    public const MIN_REASON_LENGTH = 10;

    public function authorize(): bool
    {
        $evaluation = $this->route('evaluation');
        $user = $this->user();

        return $evaluation instanceof Evaluation
            && $user !== null
            && $user->can('update', $evaluation);
    }

    protected function prepareForValidation(): void
    {
        foreach (['feedback', 'revision_reason'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([$field => trim($value)]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'score' => ['required', 'numeric', 'min:0', 'max:'.$this->maxScore(), 'decimal:0,2'],
            'feedback' => ['required', 'string', 'min:'.self::MIN_FEEDBACK_LENGTH, 'max:10000'],
            'revision_reason' => ['required', 'string', 'min:'.self::MIN_REASON_LENGTH, 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'score.max' => __('grades.errors.score_above_max', ['max' => $this->maxScore()]),
            'score.min' => __('grades.errors.score_below_zero'),
        ];
    }

    public function evaluation(): Evaluation
    {
        /** @var Evaluation $evaluation */
        $evaluation = $this->route('evaluation');

        return $evaluation;
    }

    /**
     * The graded item's own ceiling. Falls back to zero, which refuses every
     * score, rather than to an open bound (CONSTITUTION Art. 7).
     *
     * The snapshot on the evaluation row wins, exactly as EvaluationRecorder's
     * own resolution does: widening an assignment after a mark was recorded must
     * not retroactively change what that mark was measured against (BR-12,
     * PRD §9.15.4). Only a row written before the column existed falls through
     * to the live ceiling, and `entity_id` names the SUBMISSION, so reaching the
     * assignment or the final project takes two hops.
     */
    public function maxScore(): float
    {
        $evaluation = $this->route('evaluation');

        if (! $evaluation instanceof Evaluation) {
            return 0.0;
        }

        $snapshot = $evaluation->getAttribute('max_score');

        if (is_numeric($snapshot)) {
            return (float) $snapshot;
        }

        $entityId = $evaluation->getAttribute('entity_id');

        if (! is_string($entityId) || $entityId === '') {
            return 0.0;
        }

        if ($evaluation->entity_type === EvaluationEntity::FinalProject) {
            $projectId = ProjectSubmission::query()->whereKey($entityId)->value('final_project_id');
            $max = $projectId === null
                ? null
                : FinalProject::query()->whereKey($projectId)->value('max_score');
        } else {
            $assignmentId = Submission::query()->whereKey($entityId)->value('assignment_id');
            $max = $assignmentId === null
                ? null
                : Assignment::query()->whereKey($assignmentId)->value('max_score');
        }

        return is_numeric($max) ? (float) $max : 0.0;
    }
}
