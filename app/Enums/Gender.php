<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Gender recorded on a participant profile.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum Gender: string
{
    use HasEnumValues;

    case Male = 'male';
    case Female = 'female';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.gender.'.$this->value);
    }
}
