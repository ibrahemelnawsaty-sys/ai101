<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Evaluation;
use App\Models\Profile;
use App\Models\ProjectSubmission;
use App\Models\Submission;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * The participant's own hand-in — an assignment submission or a final-project
 * submission. Both screens read the same shape, so both get the same presenter.
 *
 * A resubmission never replaces an earlier one: this presenter describes the
 * newest version and the version number it carries (BR-19). The grade and the
 * trainer's note are published in full, never clipped — BR-13 makes the note
 * mandatory and PRD §9.15.3 makes showing all of it mandatory too.
 *
 * @see BR-13, BR-18, BR-19, BR-22 · PRD §9.11.2, §9.14, §9.15.3
 */
final class SubmissionPresenter extends ViewModel
{
    public static function fromAssignment(Submission $submission, ?Evaluation $evaluation, float $maxScore): self
    {
        return new self(self::shared($submission, $evaluation, $maxScore) + [
            'note' => Present::text($submission->getAttribute('note')),
            'description' => null,
        ]);
    }

    public static function fromProject(ProjectSubmission $submission, ?Evaluation $evaluation, float $maxScore): self
    {
        return new self(self::shared($submission, $evaluation, $maxScore) + [
            'note' => null,
            'description' => Present::text($submission->getAttribute('description')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function shared(
        Submission|ProjectSubmission $submission,
        ?Evaluation $evaluation,
        float $maxScore,
    ): array {
        return [
            'id' => (string) $submission->getKey(),
            'version' => (int) $submission->getAttribute('version'),
            'submittedAt' => Present::toDateTime($submission->getAttribute('submitted_at')),
            'isLate' => (bool) $submission->getAttribute('is_late'),
            'hasSubmission' => true,
            'files' => FilePresenter::collect($submission->getAttribute('files')),
            'githubUrl' => Present::text($submission->getAttribute('github_url')),
            'isGraded' => $evaluation !== null,
            'score' => $evaluation === null ? null : Present::decimal($evaluation->getAttribute('score')),
            'maxScore' => Present::decimal($maxScore),
            'feedback' => $evaluation === null ? null : Present::text($evaluation->getAttribute('feedback')),
            'graderName' => self::graderName($evaluation),
            'gradedAt' => $evaluation === null
                ? null
                : Present::toDateTime($evaluation->getAttribute('evaluated_at')),
        ];
    }

    /**
     * The evaluator's Arabic name, read only from an already-loaded relation so
     * the grade sheet stays one query (art. 19).
     */
    public static function graderName(?Evaluation $evaluation): ?string
    {
        if ($evaluation === null || ! $evaluation->relationLoaded('evaluator')) {
            return null;
        }

        $evaluator = $evaluation->getRelation('evaluator');

        if (! $evaluator instanceof User) {
            return null;
        }

        if ($evaluator->relationLoaded('profile')) {
            $profile = $evaluator->getRelation('profile');

            if ($profile instanceof Profile) {
                $name = Present::text($profile->getAttribute('full_name_ar'));

                if ($name !== null) {
                    return $name;
                }
            }
        }

        return Present::text($evaluator->getAttribute('email'));
    }
}
