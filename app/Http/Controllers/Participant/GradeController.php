<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Enums\EvaluationEntity;
use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\User;
use App\Presenters\Participant\EvaluatedItemTitle;
use App\Presenters\Participant\GradeItemPresenter;
use App\Presenters\Participant\GradeTotalPresenter;
use App\Presenters\Participant\TrendPointPresenter;
use App\Services\Grading\ScoreCalculator;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * The participant's own grades (PRD §9.15).
 *
 * Every query here is bound to the signed-in account. A participant never sees
 * a classmate's score by any route — there is no id in the URL to change, and
 * the policy refuses another account's evaluation anyway (BR-22).
 *
 * The totals come from ScoreCalculator, which is the single source of the
 * 50 + 50 = 100 arithmetic (BR-11); nothing is recomputed here.
 *
 * @see BR-11, BR-12, BR-13, BR-14, BR-22 · PRD §9.15 · CONSTITUTION Art. 6, Art. 22
 */
final class GradeController extends Controller
{
    use ExportsCsv;
    use ResolvesActiveCohort;

    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'grades';

    public function __construct(private readonly ScoreCalculator $scores) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Evaluation::class);

        $cohort = $this->activeCohort($user);

        if ($cohort === null) {
            return view('participant.grades', [
                'total' => GradeTotalPresenter::none(),
                'items' => new Collection,
                'trend' => new Collection,
                'errorState' => null,
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
            ]);
        }

        $items = Evaluation::query()
            ->with([
                'evaluator.profile',
                'submission.assignment.week',
                'projectSubmission.finalProject',
            ])
            ->forUser($user)
            ->forCohort($cohort)
            ->orderBy('evaluated_at')
            ->get();

        $projectGraded = $items->contains(
            static fn (Evaluation $item): bool => $item->getAttribute('entity_type') === EvaluationEntity::FinalProject,
        );

        // Grouped by the week the mark belongs to, which is what the sheet's
        // section headings are (PRD §9.15.3).
        $grouped = $items
            ->groupBy(static fn (Evaluation $item): string => EvaluatedItemTitle::groupOf($item))
            ->map(static fn (Collection $group): Collection => $group->map(
                static fn (Evaluation $item): GradeItemPresenter => GradeItemPresenter::graded($item),
            )->values());

        return view('participant.grades', [
            'total' => GradeTotalPresenter::from($this->scores->breakdown($user, $cohort), $projectGraded),
            'items' => $grouped,
            'trend' => $this->trend($items),
            'errorState' => null,
            'screen' => self::SCREEN,
            // Nothing marked yet is the empty state, not a zero total (Art. 17).
            'screenState' => ScreenState::of($items->isEmpty()),
        ]);
    }

    /**
     * The weekly performance trend - one bar per group, each the share of that
     * group's own available points that was actually awarded (PRD §9.15.3).
     *
     * @param  Collection<int, Evaluation>  $items
     * @return Collection<int, TrendPointPresenter>
     */
    private function trend(Collection $items): Collection
    {
        return $items
            ->groupBy(static fn (Evaluation $item): string => EvaluatedItemTitle::groupOf($item))
            ->map(static function (Collection $group, string $label): TrendPointPresenter {
                $earned = 0.0;
                $available = 0.0;

                foreach ($group as $item) {
                    $earned += (float) $item->getAttribute('score');
                    $available += (float) $item->getAttribute('max_score');
                }

                return TrendPointPresenter::from($label, $earned, $available);
            })
            ->values();
    }

    /**
     * The same rows the screen shows, as a CSV the participant can keep. Latin
     * digits and UTF-8 with a byte-order mark, so a spreadsheet opens the
     * Arabic correctly (Art. 15).
     */
    public function export(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Evaluation::class);

        $rows = Evaluation::query()
            ->where('user_id', $user->getKey())
            ->orderBy('evaluated_at')
            ->get();

        $lines = ["\u{FEFF}".$this->csvRow([
            __('grades.export.item'),
            __('grades.export.score'),
            __('grades.export.feedback'),
        ])];

        foreach ($rows as $row) {
            $lines[] = $this->csvRow([
                (string) $row->getAttribute('entity_id'),
                (string) $row->getAttribute('score'),
                (string) $row->getAttribute('feedback'),
            ]);
        }

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="athar-grades.csv"',
        ]);
    }
}
