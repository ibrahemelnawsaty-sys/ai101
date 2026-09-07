<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Support\ViewModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The cohort attendance matrix: one column per session, one row per person.
 *
 * The rows are paginated because a cohort can outgrow a page and a matrix is
 * the widest table on the platform (art. 19). The columns are the sessions in
 * schedule order, and every row's cells are built against that same order — so
 * a cell can never drift out of its column.
 *
 * @see BR-08, BR-09, BR-23 · PRD §9.9.7 · CONSTITUTION art. 19
 */
final class AttendanceMatrix extends ViewModel
{
    /**
     * @param  Collection<int, SessionRow>  $sessions
     * @param  LengthAwarePaginator<int, MatrixRow>  $rows
     */
    public static function of(Collection $sessions, LengthAwarePaginator $rows): self
    {
        return new self([
            'sessions' => $sessions,
            'rows' => $rows,
        ]);
    }
}
