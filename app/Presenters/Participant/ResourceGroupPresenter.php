<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Support\ViewModel;
use Illuminate\Support\Collection;

/**
 * One section of the training kit — a week, or the general shelf (PRD §9.12).
 *
 * A group with nothing in it still renders, with its own empty sentence, so a
 * week that has no material yet is visibly empty rather than missing (art. 17).
 *
 * @see BR-22 · PRD §9.12
 */
final class ResourceGroupPresenter extends ViewModel
{
    /**
     * @param  Collection<int, ResourcePresenter>  $items
     */
    public static function from(string $title, Collection $items): self
    {
        return new self([
            'title' => $title,
            'items' => $items,
        ]);
    }
}
