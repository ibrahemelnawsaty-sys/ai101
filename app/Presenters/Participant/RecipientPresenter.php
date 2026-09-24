<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Profile;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * One person in the "new conversation" picker (D-118): a name to recognise,
 * the role that explains why they are on the list, and the id the start
 * endpoint receives. Who is on the list is ConversationRules' alone.
 *
 * @see PRD §9.13 · D-118
 */
final class RecipientPresenter extends ViewModel
{
    public static function from(User $user): self
    {
        $profile = $user->relationLoaded('profile') ? $user->getRelation('profile') : null;
        $name = $profile instanceof Profile ? Present::text($profile->getAttribute('full_name_ar')) : null;

        return new self([
            'id' => (string) $user->getKey(),
            'name' => $name ?? (string) $user->getAttribute('email'),
            'roleLabel' => $user->role->label(),
        ]);
    }

    /**
     * The row as the radio group reads it.
     *
     * @return array{value: string, label: string, description: string}
     */
    public function option(): array
    {
        return [
            'value' => (string) $this->get('id'),
            'label' => (string) $this->get('name'),
            'description' => (string) $this->get('roleLabel'),
        ];
    }
}
