<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Publication state of a training program.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum ProgramStatus: string
{
    use HasEnumValues;

    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.program_status.'.$this->value);
    }
}
