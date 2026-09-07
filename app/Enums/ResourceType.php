<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Kind of item in the training resource pack.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum ResourceType: string
{
    use HasEnumValues;

    case File = 'file';
    case Link = 'link';
    case Video = 'video';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.resource_type.'.$this->value);
    }
}
