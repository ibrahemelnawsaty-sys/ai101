<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;

/**
 * One assignment on the trainer's board.
 *
 * `submittedCount` against `cohortSize` is the only figure here that says
 * anything about people, and both come from counts the controller ran once for
 * the whole page rather than per row (art. 19).
 *
 * A draft is invisible to participants. That is a property of the query scope
 * and the policy, not of this row — the row merely says which state it is in
 * (art. 5).
 *
 * @see BR-11, BR-17, BR-18, BR-23 · PRD §9.11.3, §9.15.1
 */
final class AssignmentRow extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    public static function from(Assignment $assignment, int $cohortSize): self
    {
        $week = self::related($assignment, 'week');
        $status = $assignment->getAttribute('status');
        $status = $status instanceof AssignmentStatus ? $status : null;

        return new self([
            'id' => (string) $assignment->getKey(),
            'title' => (string) $assignment->getAttribute('title'),
            'weekTitle' => self::text($week, 'title'),
            'dueAt' => $assignment->getAttribute('due_at'),
            'maxScore' => self::score((float) $assignment->getAttribute('max_score')),
            'isMandatory' => (bool) $assignment->getAttribute('is_mandatory'),
            'submittedCount' => (int) ($assignment->getAttribute('submissions_count') ?? 0),
            'cohortSize' => $cohortSize,
            'statusLabel' => $status?->label() ?? '—',
            'statusVariant' => self::assignmentVariantOf($status),
        ]);
    }
}
