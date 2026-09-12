<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Enums\SubmissionStatus;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Presenters\Concerns\PresentsPeople;
use App\Presenters\Concerns\PresentsVariants;
use App\Presenters\Shared\FileLink;
use App\Support\SignedFiles;
use App\Support\ViewModel;

/**
 * One handed-in final project on the trainer's screen (PRD §9.14, §9.15).
 *
 * The same shape serves the table and the grading panel beside it, so the two
 * can never disagree about what was handed in or what mark it carries. The
 * ceiling comes from the final project's own `max_score`, which is the number
 * BR-12 refuses to be exceeded and never a figure typed into a template.
 *
 * `downloadUrl` stays null for the same reason as SubmissionRow: no signed
 * download route for a submitted file exists yet, and a stored path is never a
 * URL (art. 24).
 *
 * @see BR-12, BR-13, BR-23 · PRD §9.14, §9.15 · CONSTITUTION art. 5, art. 18, art. 24
 */
final class ProjectSubmissionRow extends ViewModel
{
    use PresentsPeople;
    use PresentsVariants;

    public static function from(ProjectSubmission $submission, float $maxScore): self
    {
        $user = self::related($submission, 'user');
        $evaluation = self::related($submission, 'latestEvaluation');

        $rawScore = self::attr($evaluation, 'score');
        $isGraded = $rawScore !== null;

        $effective = $isGraded ? SubmissionStatus::Graded : SubmissionStatus::Submitted;

        return new self([
            'id' => (string) $submission->getKey(),
            'participantId' => $user instanceof User ? (string) $user->getKey() : null,
            'participantName' => self::personName($user),
            'name' => self::personName($user),
            'email' => self::personEmail($user),

            'hasSubmission' => true,
            'files' => FileLink::collection(
                $submission->getAttribute('files'),
                SignedFiles::for('files.projectSubmission', 'projectSubmission', $submission),
            ),
            'githubUrl' => self::stringOrNull($submission->getAttribute('github_url')),
            // `project_submissions` names this column `description`, not
            // `note` as `submissions` does (PROJECT-CONTRACT §4).
            'note' => self::stringOrNull($submission->getAttribute('description')),
            'version' => (int) $submission->getAttribute('version'),
            'submittedAt' => $submission->getAttribute('submitted_at'),
            'isLate' => (bool) $submission->getAttribute('is_late'),

            'isGraded' => $isGraded,
            'score' => $isGraded ? self::score((float) $rawScore) : null,
            'feedback' => self::stringOrNull(self::attr($evaluation, 'feedback')),
            'gradedAt' => self::attr($evaluation, 'evaluated_at'),
            'maxScore' => self::score($maxScore),
            'scoreMax' => self::score($maxScore),

            'stateLabel' => $effective->label(),
            'stateVariant' => self::submissionVariantOf($effective),
            'stateIcon' => self::submissionIconOf($effective),
        ]);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
