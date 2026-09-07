<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * One bar of the weekly performance trend (PRD §9.15.3).
 *
 * The bar shows a share of the points available in that week, so a week worth
 * ten points and a week worth twenty are comparable at a glance.
 *
 * @see BR-11, BR-22 · PRD §9.15.3
 */
final class TrendPointPresenter extends ViewModel
{
    public static function from(string $label, float $earned, float $available): self
    {
        $percent = $available <= 0.0 ? 0.0 : ($earned / $available) * 100;

        return new self([
            'label' => $label,
            'percent' => Present::decimal(Present::clampPercent($percent)),
        ]);
    }
}
