<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinalProjectField;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * The fields of a final project's hand-in form (D-121).
 *
 * They are part of the project's settings, and D-110 made those the general
 * supervisor's alone: a trainer reads the brief and grades, a participant
 * fills the form in — neither changes what it asks. Adding a field is asked
 * of FinalProjectPolicy::update() on the project it joins; changing, moving
 * and removing one is asked here. Every write is refused during an account
 * preview (BR-33).
 *
 * @see BR-31, BR-33 · PRD §4.2, §9.14 · D-110, D-121 · CONSTITUTION Art. 5, Art. 22
 */
final class FinalProjectFieldPolicy
{
    use InteractsWithScope;

    public function update(User $user, FinalProjectField $field): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function delete(User $user, FinalProjectField $field): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }
}
