<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Models\ProjectSubmission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording the final-project grade against a project submission.
 *
 * Same two rules as an assignment grade — the score never exceeds the item's
 * own ceiling (BR-12) and the feedback is mandatory and at least ten characters
 * long (BR-13). The ceiling is read from the final project, not the payload.
 *
 * @see BR-11, BR-12, BR-13, BR-23 · PRD §9.14, §9.15 · CONSTITUTION Art. 5
 */
final class StoreProjectEvaluationRequest extends FormRequest
{
    public const MIN_FEEDBACK_LENGTH = 10;

    public function authorize(): bool
    {
        $submission = $this->route('submission');
        $user = $this->user();

        return $submission instanceof ProjectSubmission
            && $user !== null
            && $user->can('evaluate', $submission);
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
            'feedback.required' => __('grades.errors.feedback_required'),
            'feedback.min' => __('grades.errors.feedback_too_short'),
        ];
    }

    public function submission(): ProjectSubmission
    {
        /** @var ProjectSubmission $submission */
        $submission = $this->route('submission');

        return $submission;
    }

    public function maxScore(): float
    {
        $submission = $this->route('submission');

        if (! $submission instanceof ProjectSubmission) {
            return 0.0;
        }

        $project = $submission->relationLoaded('finalProject')
            ? $submission->finalProject
            : $submission->finalProject()->first();

        $max = $project?->getAttribute('max_score');

        return is_numeric($max) ? (float) $max : 0.0;
    }
}
