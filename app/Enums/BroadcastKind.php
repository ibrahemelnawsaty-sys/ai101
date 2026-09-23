<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * What an administrator sent to a cohort from the send screen (D-87).
 *
 * @see PRD §9.16, §9.18 · D-87
 */
enum BroadcastKind: string
{
    use HasEnumValues;

    /** Their own subject and words. */
    case Message = 'message';

    /** Every upcoming session of the cohort, in one letter per trainee. */
    case Sessions = 'sessions';

    /** Each trainee's own unsubmitted work, in one letter per trainee. */
    case Assignments = 'assignments';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.broadcast_kind.'.$this->value);
    }
}
