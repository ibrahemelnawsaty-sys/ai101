<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Shared helpers for every backed string enum in the platform.
 *
 * @see PROJECT-CONTRACT.md §3
 */
trait HasEnumValues
{
    /**
     * Every raw string value of the enum, for validation rules and seeders.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
