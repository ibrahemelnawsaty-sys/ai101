<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Assignment;
use App\Presenters\Support\Present;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;

/**
 * One row of the dashboard's due-assignments card.
 *
 * The remaining-time colour is decided here against Clock::now() and never in
 * the browser: PRD §9.11.1 makes it normal above forty-eight hours, warning
 * under forty-eight, danger under six (BR-07, art. 5).
 *
 * @see BR-07, BR-17, BR-22 · PRD §9.5.3, §9.11.1
 */
final class DueAssignmentPresenter extends ViewModel
{
    public static function from(Assignment $assignment, CarbonImmutable $now): self
    {
        $dueAt = Present::toDateTime($assignment->getAttribute('due_at'));
        $variant = Present::urgencyVariant($dueAt, $now);

        return new self([
            'id' => (string) $assignment->getKey(),
            'title' => (string) $assignment->getAttribute('title'),
            'dueAt' => $dueAt,
            'isMandatory' => (bool) $assignment->getAttribute('is_mandatory'),
            'maxScore' => (int) $assignment->getAttribute('max_score'),
            'urgencyVariant' => $variant,
            'urgencyIcon' => Present::urgencyIcon($variant),
            'remainingLabel' => Present::remainingLabel($dueAt, $now),
        ]);
    }
}
