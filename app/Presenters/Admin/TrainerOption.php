<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\ViewModel;

/**
 * One trainer already assigned to a cohort.
 *
 * The e-mail is shown because it is how the administrator identifies the right
 * account when two trainers share a name; nothing else about the account
 * appears, because nothing else is needed to un-assign them.
 *
 * @see BR-23 · PRD §4.2
 */
final class TrainerOption extends ViewModel
{
    use PresentsFormValues;

    public static function from(User $trainer): self
    {
        $profile = self::related($trainer, 'profile');

        return new self([
            'id' => (string) $trainer->getKey(),
            'name' => (string) ($profile?->getAttribute('full_name_ar') ?? $trainer->getAttribute('email')),
            'email' => (string) $trainer->getAttribute('email'),
        ]);
    }
}
