<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * State of one of the ten journey steps for a participant.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum JourneyStepStatus: string
{
    use HasEnumValues;

    case Locked = 'locked';
    case Current = 'current';
    case Completed = 'completed';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.journey_step_status.'.$this->value);
    }
}
