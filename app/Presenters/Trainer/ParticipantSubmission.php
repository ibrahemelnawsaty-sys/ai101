<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Enums\SubmissionStatus;
use App\Models\Assignment;
use App\Models\Submission;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;

/**
 * One assignment as it stands for one participant, on the trainer's profile
 * panel (PRD §4.2, §9.11.3).
 *
 * The list is built from the cohort's assignments, not from the submissions
 * table, so an assignment that was never handed in still appears — with
 * `hasSubmission` false and the "not submitted" wording. A table built the
 * other way round shows a clean sheet for a participant who has done nothing,
 * which is the opposite of what a trainer needs to see.
 *
 * The published surface is declared below so a reader outside Blade - the
 * profile presenter folds these rows into one "is anything graded yet" answer -
 * gets the same shape the template sees, instead of an unchecked magic read.
 *
 * @see BR-19, BR-22, BR-23 · PRD §4.2, §9.11.3 · CONSTITUTION art. 5, art. 18
 *
 * @property-read string|null $id
 * @property-read string $assignmentTitle
 * @property-read bool $hasSubmission
 * @property-read mixed $submittedAt
 * @property-read bool $isLate
 * @property-read int $version
 * @property-read bool $isGraded
 * @property-read string $score
 * @property-read string $maxScore
 * @property-read mixed $feedback
 * @property-read mixed $gradedAt
 * @property-read string $graderName
 * @property-read array<int, mixed> $files
 * @property-read string $stateLabel
 * @property-read string $stateVariant
 * @property-read string $stateIcon
 */
final class ParticipantSubmission extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    public static function of(Assignment $assignment, ?Submission $submission): self
    {
        $evaluation = $submission === null ? null : self::related($submission, 'latestEvaluation');
        $rawScore = self::attr($evaluation, 'score');
        $isGraded = $rawScore !== null;

        $status = $submission?->getAttribute('status');
        $status = $status instanceof SubmissionStatus ? $status : null;

        return new self([
            // The grading panel is addressed by SUBMISSION id; a row with
            // nothing handed in has none, and the template's `hasSubmission`
            // branch is what keeps it from linking to one.
            'id' => $submission === null ? null : (string) $submission->getKey(),
            'assignmentTitle' => self::text($assignment, 'title'),
            'hasSubmission' => $submission !== null,
            'submittedAt' => $submission?->getAttribute('submitted_at'),
            'isLate' => (bool) ($submission?->getAttribute('is_late') ?? false),
            'version' => (int) ($submission?->getAttribute('version') ?? 0),
            'isGraded' => $isGraded,
            'score' => $isGraded ? self::score((float) $rawScore) : '—',
            'maxScore' => self::score((float) $assignment->getAttribute('max_score')),
            'feedback' => self::attr($evaluation, 'feedback'),
            'gradedAt' => self::attr($evaluation, 'evaluated_at'),
            'graderName' => '—',
            'files' => [],
            'stateLabel' => self::stateLabel($submission, $status, $isGraded),
            'stateVariant' => self::stateVariant($submission, $status, $isGraded),
            'stateIcon' => self::stateIcon($submission, $status, $isGraded),
        ]);
    }

    private static function stateLabel(?Submission $submission, ?SubmissionStatus $status, bool $isGraded): string
    {
        if ($submission === null) {
            return (string) __('trainer.assignments.not_submitted');
        }

        if ($isGraded) {
            return SubmissionStatus::Graded->label();
        }

        return $status?->label() ?? SubmissionStatus::Submitted->label();
    }

    private static function stateVariant(?Submission $submission, ?SubmissionStatus $status, bool $isGraded): string
    {
        if ($submission === null) {
            return 'error';
        }

        return self::submissionVariantOf($isGraded ? SubmissionStatus::Graded : $status);
    }

    private static function stateIcon(?Submission $submission, ?SubmissionStatus $status, bool $isGraded): string
    {
        if ($submission === null) {
            return 'warn';
        }

        return self::submissionIconOf($isGraded ? SubmissionStatus::Graded : $status);
    }
}
