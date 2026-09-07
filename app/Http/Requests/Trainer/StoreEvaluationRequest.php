<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Models\Submission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording a grade against a submission.
 *
 * Two business rules are enforced here and again in the database:
 *   BR-12 — the score is at least zero and never above the item's max_score.
 *   BR-13 — feedback is mandatory and at least 10 characters long.
 *
 * The upper bound is read from the assignment being graded, not from the
 * request, so a crafted payload cannot raise its own ceiling.
 *
 * @see BR-12, BR-13, BR-23 · PRD §9.15.2, §9.15.4 · CONSTITUTION Art. 5
 */
final class StoreEvaluationRequest extends FormRequest
{
    /** BR-13: minimum feedback length, in characters. */
    public const MIN_FEEDBACK_LENGTH = 10;

    public function authorize(): bool
    {
        $submission = $this->route('submission');

        return $submission instanceof Submission
            && $this->user() !== null
            && $this->user()->can('evaluate', $submission);
    }

    protected function prepareForValidation(): void
    {
        $feedback = $this->input('feedback');

        if (is_string($feedback)) {
            $this->merge(['feedback' => trim($feedback)]);
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
            'score.required' => __('grades.errors.score_required'),
            'feedback.required' => __('grades.errors.feedback_required'),
            'feedback.min' => __('grades.errors.feedback_too_short'),
        ];
    }

    public function submission(): Submission
    {
        /** @var Submission $submission */
        $submission = $this->route('submission');

        return $submission;
    }

    /** The item's own ceiling, taken from the assignment (BR-12). */
    public function maxScore(): float
    {
        $submission = $this->route('submission');

        if (! $submission instanceof Submission) {
            return 0.0;
        }

        $assignment = $submission->relationLoaded('assignment')
            ? $submission->assignment
            : $submission->assignment()->first();

        return (float) ($assignment?->max_score ?? 0);
    }
}
