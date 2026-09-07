<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Kind of internal messaging thread.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum ThreadType: string
{
    use HasEnumValues;

    case TrainerDm = 'trainer_dm';
    case Group = 'group';
    case Announcement = 'announcement';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.thread_type.'.$this->value);
    }
}
