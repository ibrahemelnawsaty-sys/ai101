<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\CohortStatus;
use App\Http\Controllers\Controller;
use App\Models\Cohort;
use App\Models\LandingSetting;
use App\Services\Grading\ScoreCalculator;
use App\Services\Time\Clock;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;

/**
 * The landing page.
 *
 * Every sentence a visitor reads about the programme comes out of the database
 * and is edited from the admin panel — nothing describing the programme is
 * written into this file or into the template (BR-31, BR-36).
 *
 * The countdown reference is the server instant, handed to the page once as an
 * ISO string; the browser may animate from it but never decides it (BR-07).
 *
 * @see BR-07, BR-11, BR-25, BR-26, BR-31, BR-36 · PRD §9.1 · CONSTITUTION Art. 6, Art. 11
 */
final class HomeController extends Controller
{
    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'landing';

    /**
     * Which cohort the landing page describes, in order of preference. A cohort
     * that has already started still has a landing page — the programme is the
     * centre's shop window whether or not it is taking registrations today, and
     * dropping to the empty state the moment a cohort starts running made the
     * whole programme disappear from its own page (PRD §9.1.1).
     *
     * @var list<CohortStatus>
     */
    private const COHORT_PREFERENCE = [
        CohortStatus::Open,
        CohortStatus::Upcoming,
        CohortStatus::Running,
    ];

    public function index(): View
    {
        $cohort = $this->featuredCohort();
        $facts = $this->cohortFacts($cohort);

        return view('public.home', [
            'screen' => self::SCREEN,
            'screenState' => ScreenState::of($cohort === null),
            // "Closed" is a state of the registration, not of the page: PRD
            // §9.1.2 gives it its own copy and its own waiting-list form, so it
            // must never be mistaken for a generic empty screen.
            'registrationState' => $facts['is_registration_open'] === true ? 'open' : 'closed',
            'landing' => $this->landingContent($cohort),
            'cohort' => $facts,
            'serverNowIso' => Clock::now()->toIso8601String(),
            'copyrightYear' => Clock::riyadh()->year,
        ]);
    }

    /**
     * The cohort currently shown to visitors: the one open for registration,
     * else the next one starting, else the one running now. Null when the
     * centre has published none, which the template renders as its own empty
     * state (Art. 17).
     */
    private function featuredCohort(): ?Cohort
    {
        foreach (self::COHORT_PREFERENCE as $status) {
            /** @var Cohort|null $cohort */
            $cohort = Cohort::query()
                ->with(['program', 'landingSetting', 'weeks' => static fn ($query) => $query->orderBy('index')])
                ->withCount('sessions')
                ->where('status', $status->value)
                ->orderBy('start_date')
                ->first();

            if ($cohort !== null) {
                return $cohort;
            }
        }

        return null;
    }

    /**
     * Admin-managed copy, shaped as the template expects it. A section the
     * centre has not filled in yet comes back empty and the template renders
     * its own placeholder for it.
     *
     * @return array<string, mixed>
     */
    private function landingContent(?Cohort $cohort): array
    {
        $program = $cohort?->program;
        $setting = $cohort?->landingSetting;

        $weeks = $this->weekItems($cohort);

        return [
            'hero' => [
                'eyebrow' => $program?->getAttribute('name_ar'),
                'title_lead' => $program?->getAttribute('name_ar'),
                'title_gradient' => $program?->getAttribute('name_en'),
                'subtitle' => $setting?->getAttribute('hero_text') ?? $program?->getAttribute('description'),
                'chips' => [],
            ],
            'ticker' => [],
            'trust' => [],
            'about' => [
                'kicker' => null,
                'title' => $program?->getAttribute('name_ar'),
                'paragraphs' => array_filter([$program?->getAttribute('description')]),
                'tags' => [],
                'cards' => [],
            ],
            'goals' => [
                'kicker' => null,
                'title' => null,
                'lead' => null,
                'items' => $this->jsonList($program?->getAttribute('objectives')),
            ],
            'audience' => [
                'kicker' => null,
                'title' => null,
                'lead' => null,
                'items' => $this->jsonList($program?->getAttribute('target_audience')),
            ],
            'certificates' => [
                'kicker' => null,
                'title' => null,
                'lead' => null,
                'items' => $this->jsonList($program?->getAttribute('certificates')),
            ],
            'weeks' => [
                'kicker' => null,
                'title' => null,
                'items' => $weeks,
            ],
            'timeline' => [
                'kicker' => null,
                'title' => null,
                'lead' => null,
                'items' => $weeks,
            ],
            'trainers' => [
                'kicker' => null,
                'title' => null,
                'lead' => null,
                'items' => [],
            ],
            'faq' => [
                'kicker' => null,
                'title' => null,
                'items' => $this->faqItems($setting),
            ],
            'final' => [
                'eyebrow' => null,
                'title' => null,
                'body' => null,
            ],
        ];
    }

    /**
     * Seats, dates and thresholds. The seat figure a visitor sees may be
     * overridden by the centre for presentation; the real capacity check still
     * happens at registration time, on the server.
     *
     * @return array<string, mixed>
     */
    private function cohortFacts(?Cohort $cohort): array
    {
        $points = [
            'assignments_points' => ScoreCalculator::ASSIGNMENTS_TOTAL,
            'assignments_total' => ScoreCalculator::ASSIGNMENTS_TOTAL,
            'project_points' => ScoreCalculator::PROJECT_TOTAL,
            'grand_total' => ScoreCalculator::GRAND_TOTAL,
        ];

        if ($cohort === null) {
            return array_merge($points, [
                'is_registration_open' => false,
                'seats_total' => null,
                'seats_taken' => 0,
                'seats_remaining' => null,
                'seats_taken_percent' => 0,
                'registration_closes_at_iso' => null,
                'sessions_total' => 0,
                'pass_score' => 0,
                'min_attendance_rate' => 0,
            ]);
        }

        $setting = $cohort->landingSetting;
        $capacity = (int) $cohort->capacity;
        $taken = (int) $cohort->seats_taken;
        $override = $setting?->getAttribute('seats_remaining_override');
        $closesAt = $cohort->registration_closes_at;

        return array_merge($points, [
            'is_registration_open' => $this->registrationIsOpen($cohort),
            'seats_total' => $capacity,
            'seats_taken' => $taken,
            'seats_remaining' => is_numeric($override) ? (int) $override : $cohort->seatsRemaining(),
            'seats_taken_percent' => $capacity > 0 ? (int) round($taken / $capacity * 100) : 0,
            'registration_closes_at_iso' => $closesAt === null ? null : Clock::toUtc($closesAt)->toIso8601String(),
            'sessions_total' => (int) ($cohort->getAttribute('sessions_count') ?? 0),
            'pass_score' => (int) $cohort->pass_score,
            'min_attendance_rate' => (int) $cohort->min_attendance_rate,
        ]);
    }

    /**
     * Registration is open when the centre says so, seats remain, and the
     * closing instant has not passed on the server clock (BR-07).
     */
    private function registrationIsOpen(Cohort $cohort): bool
    {
        $setting = $cohort->landingSetting;

        if ($setting !== null && ! (bool) $setting->getAttribute('is_registration_open')) {
            return false;
        }

        if ($cohort->status !== CohortStatus::Open) {
            return false;
        }

        if ($cohort->seatsRemaining() <= 0) {
            return false;
        }

        $closesAt = $cohort->registration_closes_at;

        return $closesAt === null || Clock::now()->lessThan(Clock::toUtc($closesAt));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function weekItems(?Cohort $cohort): array
    {
        if ($cohort === null) {
            return [];
        }

        // array_values, not Collection::values(): the latter is a list at run
        // time but all() still types as array<int, ...>.
        return array_values(
            $cohort->weeks
                ->map(static fn ($week): array => [
                    'index' => (int) $week->getAttribute('index'),
                    'title' => $week->getAttribute('title'),
                    'objectives' => $week->getAttribute('objectives'),
                ])
                ->all(),
        );
    }

    /**
     * @return list<array<string, string>>
     */
    private function faqItems(?LandingSetting $setting): array
    {
        $faq = $setting?->getAttribute('faq');

        if (! is_array($faq)) {
            return [];
        }

        $items = [];

        foreach ($faq as $entry) {
            if (is_array($entry) && isset($entry['question'], $entry['answer'])) {
                $items[] = [
                    'question' => (string) $entry['question'],
                    'answer' => (string) $entry['answer'],
                ];
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function jsonList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }
}
