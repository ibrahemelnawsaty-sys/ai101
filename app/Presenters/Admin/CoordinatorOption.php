<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\ViewModel;

/**
 * One coordinator already assigned to a cohort, and whether they are its
 * primary coordinator — the one a support ticket reaches first (D-124).
 *
 * `canMakePrimary` is decided here, not in the template: the button shows only
 * where there is a real choice (two coordinators or more) and only beside the
 * ones who are not primary already.
 *
 * @see D-105 · D-124 · PROJECT-CONTRACT §16
 */
final class CoordinatorOption extends ViewModel
{
    use PresentsFormValues;

    public static function from(User $coordinator, ?string $primaryId, bool $hasChoice): self
    {
        $profile = self::related($coordinator, 'profile');
        $id = (string) $coordinator->getKey();
        $isPrimary = $primaryId !== null && $primaryId === $id;

        return new self([
            'id' => $id,
            'name' => (string) ($profile?->getAttribute('full_name_ar') ?? $coordinator->getAttribute('email')),
            'email' => (string) $coordinator->getAttribute('email'),
            'isPrimary' => $isPrimary,
            'canMakePrimary' => $hasChoice && ! $isPrimary,
        ]);
    }
}
