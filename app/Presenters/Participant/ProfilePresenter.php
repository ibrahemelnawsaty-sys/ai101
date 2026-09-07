<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Profile;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * The account screen's identity block (PRD §9.4).
 *
 * The four Arabic name parts are stored as first/second/third/last; the screen
 * asks for them as first/father/grandfather/family, which is the same four
 * facts under the names the form uses. The translation happens once, here.
 *
 * The e-mail address is published for display only — it is not editable on this
 * screen, because changing it re-opens verification and belongs to its own flow.
 *
 * @see BR-22, BR-29, BR-33 · PRD §9.4
 */
final class ProfilePresenter extends ViewModel
{
    public static function from(User $user, ?Profile $profile): self
    {
        return new self([
            'firstNameAr' => self::part($profile, 'first_name_ar'),
            'fatherNameAr' => self::part($profile, 'second_name_ar'),
            'grandfatherNameAr' => self::part($profile, 'third_name_ar'),
            'familyNameAr' => self::part($profile, 'last_name_ar'),
            'firstNameEn' => self::part($profile, 'first_name_en'),
            'familyNameEn' => self::part($profile, 'last_name_en'),
            'fullNameAr' => $profile === null ? '' : (string) $profile->getAttribute('full_name_ar'),
            'fullNameEn' => $profile === null ? '' : (string) $profile->getAttribute('full_name_en'),
            'email' => (string) $user->getAttribute('email'),
            'phone' => self::part($profile, 'phone'),
            'photoUrl' => $profile === null ? null : Present::text($profile->getAttribute('avatar_url')),
        ]);
    }

    private static function part(?Profile $profile, string $attribute): string
    {
        return $profile === null ? '' : (string) ($profile->getAttribute($attribute) ?? '');
    }
}
