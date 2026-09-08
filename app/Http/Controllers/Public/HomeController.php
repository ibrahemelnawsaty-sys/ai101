<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\CohortStatus;
use App\Http\Controllers\Controller;
use App\Models\Cohort;
use App\Models\LandingSetting;
use App\Services\Grading\ScoreCalculator;
use App\Services\Time\Clock;
use App\Services\Time\RiyadhFormatter;
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

    public function index(RiyadhFormatter $formatter): View
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
            'landing' => $this->landingContent($cohort, $formatter),
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
                ->with(['program', 'landingSetting', 'weeks' => static fn ($query) => $query->withCount('sessions')->orderBy('index')])
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
    private function landingContent(?Cohort $cohort, RiyadhFormatter $formatter): array
    {
        $program = $cohort?->program;
        $setting = $cohort?->landingSetting;

        $weeks = $this->weekItems($cohort, $formatter);

        return [
            'hero' => [
                'eyebrow' => $program?->getAttribute('name_ar'),
                'title_lead' => $program?->getAttribute('name_ar'),
                'title_gradient' => $program?->getAttribute('name_en'),
                'subtitle' => $setting?->getAttribute('hero_text') ?? $program?->getAttribute('description'),
                'chips' => $this->heroChips($cohort, $weeks),
            ],
            'ticker' => $this->tickerTopics($weeks),
            'trust' => $this->trustStats($cohort, $weeks),
            'about' => [
                'kicker' => __('landing.headings.about.kicker'),
                'title' => $program?->getAttribute('name_ar'),
                'paragraphs' => array_filter([$program?->getAttribute('description')]),
                // Nothing in the schema backs either of these, and the sentences
                // that do exist are already spent on the goals, audience and
                // certificate sections. Inventing copy here would be worse than
                // an absent row, so both stay empty and the template drops to a
                // single column instead of holding an empty half-grid.
                'tags' => [],
                'cards' => [],
            ],
            'goals' => [
                'kicker' => __('landing.headings.goals.kicker'),
                'title' => __('landing.headings.goals.title'),
                'lead' => __('landing.headings.goals.lead'),
                'items' => $this->jsonCards($program?->getAttribute('objectives')),
            ],
            'audience' => [
                'kicker' => __('landing.headings.audience.kicker'),
                'title' => __('landing.headings.audience.title'),
                'lead' => __('landing.headings.audience.lead'),
                'items' => $this->jsonCards($program?->getAttribute('target_audience')),
            ],
            'certificates' => [
                'kicker' => __('landing.headings.certificates.kicker'),
                'title' => __('landing.headings.certificates.title'),
                'lead' => __('landing.headings.certificates.lead'),
                'items' => $this->jsonCards($program?->getAttribute('certificates')),
            ],
            'weeks' => [
                'kicker' => __('landing.headings.weeks.kicker'),
                'title' => __('landing.headings.weeks.title'),
                'items' => $weeks,
            ],
            'timeline' => [
                'kicker' => __('landing.headings.timeline.kicker'),
                'title' => __('landing.headings.timeline.title'),
                'lead' => __('landing.headings.timeline.lead'),
                'items' => $weeks,
            ],
            // Trainers are not wired to the enrolment table yet, so the list is
            // empty by construction rather than by absence of data. The template
            // hides the whole section while it is empty instead of showing an
            // empty state that no admin action could ever clear.
            'trainers' => [
                'kicker' => __('landing.headings.trainers.kicker'),
                'title' => __('landing.headings.trainers.title'),
                'lead' => __('landing.headings.trainers.lead'),
                'items' => [],
            ],
            'faq' => [
                'kicker' => __('landing.headings.faq.kicker'),
                'title' => __('landing.headings.faq.title'),
                'items' => $this->faqItems($setting),
            ],
            'final' => [
                'eyebrow' => __('landing.headings.final.eyebrow'),
                'title' => __('landing.headings.final.title'),
                'body' => __('landing.headings.final.body'),
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
     * The hero chips, the marquee and the trust bar all used to return an empty
     * array, so three designed bands rendered as nothing at all.
     *
     * There is no table behind them — `landing_settings` carries hero copy, the
     * FAQ and two switches, and nothing else — so rather than invent marketing
     * copy or add a column, each is derived from figures the cohort already
     * holds. The numbers move when the admin moves the cohort, never from here,
     * which is what BR-31 actually asks for.
     *
     * @param  list<array<string, mixed>>  $weeks
     * @return list<array{icon: string, label: string}>
     */
    private function heroChips(?Cohort $cohort, array $weeks): array
    {
        if ($cohort === null) {
            return [];
        }

        $chips = [];

        $weekCount = count($weeks);

        if ($weekCount > 0) {
            $chips[] = ['icon' => 'cal', 'label' => trans_choice('landing.facts.chip_weeks', $weekCount, ['count' => $weekCount])];
        }

        $sessions = (int) ($cohort->getAttribute('sessions_count') ?? 0);

        if ($sessions > 0) {
            $chips[] = ['icon' => 'video', 'label' => trans_choice('landing.facts.chip_sessions', $sessions, ['count' => $sessions])];
        }

        $certificates = count($this->jsonCards($cohort->program?->getAttribute('certificates')));

        if ($certificates > 0) {
            $chips[] = ['icon' => 'badge', 'label' => trans_choice('landing.facts.chip_certificates', $certificates, ['count' => $certificates])];
        }

        return $chips;
    }

    /**
     * The marquee is decorative and `aria-hidden`, so it carries the week titles
     * rather than a second copy of anything a screen reader needs.
     *
     * @param  list<array<string, mixed>>  $weeks
     * @return list<string>
     */
    private function tickerTopics(array $weeks): array
    {
        $topics = [];

        foreach ($weeks as $week) {
            $title = $week['title'] ?? null;

            if (is_string($title) && $title !== '') {
                $topics[] = $title;
            }
        }

        return $topics;
    }

    /**
     * Four figures the centre can stand behind, every one of them already true
     * of the cohort. A band that cannot be filled honestly is left empty and
     * hidden rather than padded.
     *
     * @param  list<array<string, mixed>>  $weeks
     * @return list<array{value: int, suffix: string|null, label: string}>
     */
    private function trustStats(?Cohort $cohort, array $weeks): array
    {
        if ($cohort === null) {
            return [];
        }

        $stats = [];

        $weekCount = count($weeks);

        if ($weekCount > 0) {
            $stats[] = ['value' => $weekCount, 'suffix' => null, 'label' => __('landing.facts.trust_weeks')];
        }

        $sessions = (int) ($cohort->getAttribute('sessions_count') ?? 0);

        if ($sessions > 0) {
            $stats[] = ['value' => $sessions, 'suffix' => null, 'label' => __('landing.facts.trust_sessions')];
        }

        $stats[] = ['value' => ScoreCalculator::GRAND_TOTAL, 'suffix' => null, 'label' => __('landing.facts.trust_points')];

        $minAttendance = (int) $cohort->min_attendance_rate;

        if ($minAttendance > 0) {
            $stats[] = ['value' => $minAttendance, 'suffix' => '%', 'label' => __('landing.facts.trust_attendance')];
        }

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function weekItems(?Cohort $cohort, RiyadhFormatter $formatter): array
    {
        if ($cohort === null) {
            return [];
        }

        // array_values, not Collection::values(): the latter is a list at run
        // time but all() still types as array<int, ...>.
        return array_values(
            $cohort->weeks
                ->map(fn ($week): array => [
                    'index' => (int) $week->getAttribute('index'),
                    'title' => $week->getAttribute('title'),
                    'dates' => $this->weekRange($week, $formatter),
                    'sessions' => (int) ($week->getAttribute('sessions_count') ?? 0),
                    // The column is `objectives`; the template calls them topics.
                    // The mismatch is why the week tags never rendered.
                    'topics' => $this->jsonStrings($week->getAttribute('objectives')),
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
    private function jsonStrings(mixed $value): array
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

    /**
     * The goals, audience and certificate templates each read `title` and
     * `body` off every item. The admin stores those three lists as plain
     * strings, so `data_get($item, 'title')` resolved to null on a string and
     * all three sections rendered as empty shells: icon, number, nothing else.
     *
     * A plain string becomes the card's title, which is what those sentences
     * are. An entry that is already an array keeps whatever it carries, so the
     * day the admin screen starts storing richer objects nothing here changes.
     *
     * `icon` is carried alongside title and body because the goals section
     * renders one (see the block comment above it in home.blade.php). It is
     * named here rather than splatting the whole stored object through, so the
     * contract between this method and the view is something you can read.
     *
     * @return list<array{title: string|null, body: string|null, icon: string|null}>
     */
    private function jsonCards(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = ['title' => $item, 'body' => null, 'icon' => null];

                continue;
            }

            if (is_array($item)) {
                $title = $item['title'] ?? null;
                $body = $item['body'] ?? null;

                if (is_string($title) || is_string($body)) {
                    $icon = $item['icon'] ?? null;

                    $items[] = [
                        'title' => is_string($title) ? $title : null,
                        'body' => is_string($body) ? $body : null,
                        'icon' => is_string($icon) ? $icon : null,
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * The week's date span, shown under its title. Returns null rather than a
     * half-formed range when the model has not cast the columns to dates, so a
     * missing cast degrades to no line instead of a crash on a public page.
     */
    private function weekRange(mixed $week, RiyadhFormatter $formatter): ?string
    {
        $start = $week->getAttribute('start_date');
        $end = $week->getAttribute('end_date');

        if (! $start instanceof \DateTimeInterface || ! $end instanceof \DateTimeInterface) {
            return null;
        }

        return __('landing.sections.date_range', [
            'from' => $formatter->shortDate($start),
            'to' => $formatter->shortDate($end),
        ]);
    }
}
