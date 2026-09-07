<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * One row of the final project's marking table (PRD §9.14).
 *
 * @see BR-15, BR-16 · PRD §9.14
 */
final class ProjectCriterionPresenter extends ViewModel
{
    public static function from(string $title, mixed $maxScore): self
    {
        return new self([
            'title' => $title,
            'maxScore' => Present::decimal($maxScore),
        ]);
    }
}
