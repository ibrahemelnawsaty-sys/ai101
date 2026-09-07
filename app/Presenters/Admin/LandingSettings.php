<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\Cohort;
use App\Models\LandingSetting;
use App\Support\ViewModel;

/**
 * The landing-page editor (BR-31).
 *
 * Everything a visitor reads is a value here: the registration switch, the
 * countdown, the seat figure and its manual override, the three texts and the
 * FAQ. None of it is written in code and none of it is written in a template.
 *
 * `seatsRemaining` is the computed figure and is shown read-only beside
 * `seatsOverride`, which is what the centre may set instead. Turning the switch
 * off closes the public form on the very next request, because the registration
 * path re-reads it rather than trusting a cached page (art. 5).
 *
 * @see BR-31, BR-36 · PRD §9.1, §9.18 · CONSTITUTION art. 5, art. 6
 */
final class LandingSettings extends ViewModel
{
    public static function from(?LandingSetting $setting, ?Cohort $cohort): self
    {
        return new self([
            'isRegistrationOpen' => (bool) ($setting?->getAttribute('is_registration_open') ?? false),
            'countdownEnabled' => (bool) ($setting?->getAttribute('countdown_enabled') ?? false),
            'seatsRemaining' => $cohort?->seatsRemaining() ?? 0,
            'seatsOverride' => $setting?->getAttribute('seats_remaining_override'),
            'heroTitle' => (string) ($setting?->getAttribute('hero_title') ?? ''),
            'heroSubtitle' => (string) ($setting?->getAttribute('hero_text') ?? ''),
            'aboutBody' => (string) ($setting?->getAttribute('about_body') ?? ''),
            'faq' => FaqEntry::collection($setting?->getAttribute('faq')),
        ]);
    }
}
