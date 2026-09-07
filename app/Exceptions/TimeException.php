<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised when an instant cannot be determined with certainty.
 *
 * @see BR-07 · CONSTITUTION art. 7 (when time cannot be resolved — refuse)
 */
final class TimeException extends DomainException
{
    public static function unreadable(): self
    {
        return new self('errors.time.unreadable', [], 500);
    }
}
