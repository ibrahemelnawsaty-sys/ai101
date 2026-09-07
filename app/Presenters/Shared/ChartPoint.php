<?php

declare(strict_types=1);

namespace App\Presenters\Shared;

use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;

/**
 * One bar on a report.
 *
 * The admin and trainer report screens draw their trends with plain progress
 * bars — no chart library, no canvas — and every bar prints its own number in
 * text beside it, because colour alone never carries meaning (art. 18). This
 * view-model carries exactly what such a bar needs: a label, a raw value, the
 * maximum it is measured against, the percentage those two produce, and the
 * variant that percentage earns.
 *
 * @see PRD §9.18, §13.2 · BR-23, BR-28 · CONSTITUTION art. 16, art. 18
 */
final class ChartPoint extends ViewModel
{
    use PresentsVariants;

    /**
     * A count against a ceiling — registrations in a week, submissions for an
     * assignment. `max` is what the bar is scaled to.
     */
    public static function count(string $label, int $value, int $max): self
    {
        $ceiling = max($max, 1);
        $percent = self::percent($value / $ceiling * 100);

        return new self([
            'label' => $label,
            'value' => $value,
            'max' => $ceiling,
            'percent' => $percent,
            'variant' => 'brand',
        ]);
    }

    /**
     * A rate that is already a percentage — attendance for a session, the
     * submission rate for an assignment. `$minimum` is the cohort threshold
     * when one applies, so a bar under it reads as critical (BR-26).
     */
    public static function rate(string $label, float $percent, ?float $minimum = null): self
    {
        $rounded = self::percent($percent);

        return new self([
            'label' => $label,
            'value' => $rounded,
            'max' => 100,
            'percent' => $rounded,
            'variant' => self::rateVariant((float) $rounded, $minimum),
        ]);
    }
}
