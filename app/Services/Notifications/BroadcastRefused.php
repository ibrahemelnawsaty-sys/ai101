<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * A send the platform declined, and why, as a copy key the screen can show:
 * too soon after the last one, the same message twice, or nothing to remind
 * anybody of (D-87).
 *
 * @see D-87
 */
final class BroadcastRefused extends \RuntimeException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(
        public readonly string $reasonKey,
        public readonly array $replace = [],
    ) {
        parent::__construct($reasonKey);
    }

    public function reason(): string
    {
        return (string) __($this->reasonKey, $this->replace);
    }
}
