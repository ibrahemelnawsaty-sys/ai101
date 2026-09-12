<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Presenters\Concerns\PresentsPeople;
use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;

/**
 * One line of the trainer's submissions board (PRD §9.11.3, §9.15).
 *
 * The board used to be handed raw Submission models while the template read
 * `participantName`, `stateVariant`, `fileLabel` and nine more, so every row
 * fatalled. All of them are decided here.
 *
 * `downloadUrl` is deliberately null: there is no signed-download route for a
 * submitted file on this platform yet, and a link that goes nowhere is worse
 * than no link (CONSTITUTION art. 24 — a stored path is never a URL). The
 * template prints the file's name plainly while it stays null.
 *
 * `hasSubmission` is always true for a row built from a submission; the roster
 * shape that carries absences is ParticipantSubmission. The board no longer
 * branches on it: the "remind" button lived in its false branch and so could
 * never render, and it now sits in the toolbar, addressed to the assignment the
 * board is filtered to (D-68).
 *
 * @see BR-12, BR-13, BR-19, BR-23 · PRD §9.11.3, §9.15 · CONSTITUTION art. 5, art. 18, art. 24
 */
final class SubmissionRow extends ViewModel
{
    use PresentsPeople;
    use PresentsVariants;

    public static function from(Submission $submission): self
    {
        $user = self::related($submission, 'user');
        $assignment = self::related($submission, 'assignment');
        $evaluation = self::related($submission, 'latestEvaluation');

        $rawScore = self::attr($evaluation, 'score');
        $isGraded = $rawScore !== null;

        $status = $submission->getAttribute('status');
        $status = $status instanceof SubmissionStatus ? $status : null;
        $effective = $isGraded ? SubmissionStatus::Graded : $status;

        $files = $submission->getAttribute('files');
        $first = is_array($files) && $files !== [] ? reset($files) : null;
        $fileName = is_array($first) ? ($first['original_name'] ?? $first['name'] ?? null) : null;

        return new self([
            'id' => (string) $submission->getKey(),
            'participantId' => $user instanceof User ? (string) $user->getKey() : null,
            'assignmentId' => $assignment === null ? null : (string) $assignment->getKey(),
            'participantName' => self::personName($user),
            'name' => self::personName($user),
            'email' => self::personEmail($user),
            'assignmentTitle' => self::text($assignment, 'title'),
            'submittedAt' => $submission->getAttribute('submitted_at'),
            'isLate' => (bool) $submission->getAttribute('is_late'),
            'version' => (int) $submission->getAttribute('version'),
            'hasSubmission' => true,
            'fileLabel' => is_string($fileName) && $fileName !== '' ? $fileName : null,
            'downloadUrl' => null,
            'isGraded' => $isGraded,
            'hasScore' => $isGraded,
            'score' => $isGraded ? self::score((float) $rawScore) : '—',
            'maxScore' => self::score((float) (self::attr($assignment, 'max_score') ?? 0)),
            'scoreMax' => self::score((float) (self::attr($assignment, 'max_score') ?? 0)),
            'stateLabel' => $effective?->label() ?? SubmissionStatus::Submitted->label(),
            'stateVariant' => self::submissionVariantOf($effective),
            'stateIcon' => self::submissionIconOf($effective),
        ]);
    }
}
