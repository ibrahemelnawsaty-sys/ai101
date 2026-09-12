<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Assignment;
use App\Models\Evaluation;
use App\Models\Submission;
use App\Models\Week;
use App\Presenters\Support\Present;
use App\Support\SignedFiles;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One assignment, on the weekly list and on its own page.
 *
 * The status badge is one of PRD §9.11.1's five: not submitted, submitted,
 * under review, graded, late. The remaining-time colour is §9.11.1's three
 * bands, decided against the server clock (BR-07). Neither is computed in
 * the template.
 *
 * The upload limits are the assignment's own when the trainer set them, and the
 * platform defaults from config/athar.php otherwise — never a literal (BR-36).
 *
 * @see BR-07, BR-17, BR-18, BR-19, BR-22, BR-36 · PRD §9.11.1, §9.11.2
 */
final class AssignmentPresenter extends ViewModel
{
    private const BYTES_PER_MB = 1048576;

    public static function from(
        Assignment $assignment,
        ?Submission $submission,
        ?Evaluation $evaluation,
        CarbonImmutable $now,
    ): self {
        $dueAt = Present::toDateTime($assignment->getAttribute('due_at'));
        $urgency = Present::urgencyVariant($dueAt, $now);
        $maxScore = (int) $assignment->getAttribute('max_score');

        $maxFiles = (int) ($assignment->getAttribute('max_files') ?: config('athar.uploads.max_files'));
        $maxMb = (int) ($assignment->getAttribute('max_file_size_mb')
            ?: intdiv((int) config('athar.uploads.max_kilobytes'), 1024));

        $state = self::state($assignment, $submission, $evaluation, $now);

        return new self([
            'id' => (string) $assignment->getKey(),
            'title' => (string) $assignment->getAttribute('title'),
            'headline' => (string) $assignment->getAttribute('title'),
            'description' => Present::text($assignment->getAttribute('description')),
            // `assignments` carries no requirements column (PROJECT-CONTRACT §4),
            // so the brief's requirements list stays empty and the template's
            // isNotEmpty() guard hides the heading rather than repeating the
            // description under a second title.
            'requirements' => new Collection,
            'attachments' => FilePresenter::collect(
                $assignment->getAttribute('attachments'),
                SignedFiles::for('files.assignment', 'assignment', $assignment),
            ),
            'weekTitle' => self::weekTitle($assignment),
            'dueAt' => $dueAt,
            'isMandatory' => (bool) $assignment->getAttribute('is_mandatory'),
            'maxScore' => $maxScore,
            'isSubmitted' => $submission !== null,
            'isGraded' => $evaluation !== null,
            'score' => $evaluation === null ? null : Present::decimal($evaluation->getAttribute('score')),
            'statusLabel' => (string) __('assignments.state.'.$state),
            'statusVariant' => self::stateVariant($state),
            'statusIcon' => self::stateIcon($state),
            'urgencyVariant' => $urgency,
            'urgencyIcon' => Present::urgencyIcon($urgency),
            'remainingLabel' => Present::remainingLabel($dueAt, $now),
            'maxFiles' => $maxFiles,
            'maxFileBytes' => $maxMb * self::BYTES_PER_MB,
            'maxFileSizeLabel' => Present::fileSize($maxMb * self::BYTES_PER_MB) ?? '',
        ]);
    }

    /**
     * PRD §9.11.1 — the five badges, in the order the server can prove them.
     */
    private static function state(
        Assignment $assignment,
        ?Submission $submission,
        ?Evaluation $evaluation,
        CarbonImmutable $now,
    ): string {
        if ($evaluation !== null) {
            return 'graded';
        }

        if ($submission !== null) {
            return (bool) $submission->getAttribute('is_late') ? 'late' : 'submitted';
        }

        return Present::hasPassed(Present::toDateTime($assignment->getAttribute('due_at')), $now)
            ? 'late'
            : 'not_submitted';
    }

    private static function stateVariant(string $state): string
    {
        return match ($state) {
            'graded' => 'success',
            'submitted' => 'info',
            'under_review' => 'info',
            'late' => 'warning',
            default => 'neutral',
        };
    }

    private static function stateIcon(string $state): string
    {
        return match ($state) {
            'graded' => 'check',
            'submitted', 'under_review' => 'up',
            'late' => 'warn',
            default => 'file',
        };
    }

    private static function weekTitle(Assignment $assignment): ?string
    {
        if (! $assignment->relationLoaded('week')) {
            return null;
        }

        $week = $assignment->getRelation('week');

        return $week instanceof Week ? (string) $week->getAttribute('title') : null;
    }
}
