<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;
use Illuminate\Support\Collection;

/**
 * The three stat cards above the assignments list (PRD §9.11.1).
 *
 * The score is ScoreCalculator's, capped at the fifty BR-11 allots to
 * assignments; the counts are of the published assignments the participant can
 * actually see, which is the same collection the list below renders.
 *
 * @see BR-11, BR-17, BR-22 · PRD §9.11.1
 */
final class AssignmentsSummaryPresenter extends ViewModel
{
    /** An account with no cohort yet: nothing published, nothing pending. */
    public static function none(): self
    {
        return new self([
            'mandatoryTotal' => 0,
            'mandatoryCompleted' => 0,
            'pendingCount' => 0,
            'earnedScore' => '0',
            'assignmentsTotal' => ScoreCalculator::ASSIGNMENTS_TOTAL,
        ]);
    }

    /**
     * @param  Collection<int, Assignment>  $assignments  published assignments of this cohort
     * @param  array<string, bool>  $submitted  assignment id => the participant handed it in
     */
    public static function from(
        User $user,
        Cohort $cohort,
        ScoreCalculator $scores,
        Collection $assignments,
        array $submitted,
    ): self {
        $mandatoryTotal = 0;
        $mandatoryCompleted = 0;
        $pending = 0;

        foreach ($assignments as $assignment) {
            $id = (string) $assignment->getKey();
            $handedIn = $submitted[$id] ?? false;

            if ((bool) $assignment->getAttribute('is_mandatory')) {
                $mandatoryTotal++;

                if ($handedIn) {
                    $mandatoryCompleted++;
                }
            }

            if (! $handedIn) {
                $pending++;
            }
        }

        return new self([
            'mandatoryTotal' => $mandatoryTotal,
            'mandatoryCompleted' => $mandatoryCompleted,
            'pendingCount' => $pending,
            'earnedScore' => Present::decimal($scores->assignmentsScore($user, $cohort)),
            'assignmentsTotal' => ScoreCalculator::ASSIGNMENTS_TOTAL,
        ]);
    }
}
