<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Kind of a scheduled training session.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum SessionType: string
{
    use HasEnumValues;

    case Intro = 'intro';
    case Training = 'training';
    case Project = 'project';
    case Closing = 'closing';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.session_type.'.$this->value);
    }
}
