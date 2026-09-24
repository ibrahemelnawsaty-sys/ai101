<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Kind of internal messaging thread.
 *
 * @see PROJECT-CONTRACT.md §3 · D-118
 */
enum ThreadType: string
{
    use HasEnumValues;

    case TrainerDm = 'trainer_dm';
    case Group = 'group';
    case Announcement = 'announcement';
    /** A conversation one person started with another (D-118). */
    case Direct = 'direct';

    /** One person talking to one other: the read stamp means something here. */
    public function isOneToOne(): bool
    {
        return $this === self::TrainerDm || $this === self::Direct;
    }

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.thread_type.'.$this->value);
    }
}
