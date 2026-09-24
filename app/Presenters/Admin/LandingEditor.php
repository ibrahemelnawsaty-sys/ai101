<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\Cohort;
use App\Models\LandingSetting;
use App\Services\Landing\LandingCatalog;
use App\Support\ViewModel;
use Illuminate\Support\Facades\Lang;

/**
 * Everything the landing-page editor needs, in one payload (BR-31).
 *
 * The editor is modelled on the approved Sajaya editor: a tab per section of
 * the page, a card per block of text inside it, every text shown in Arabic,
 * English or both, a draft that stays in the editor's browser until it is
 * published, and the real page rendered beside it.
 *
 * Every label the editor prints is resolved HERE through __(), so the script
 * that draws the editor holds no sentence of its own (Constitution, Art. 15).
 *
 * The payload carries three layers for every text, the same three the Sajaya
 * editor reasons with:
 *   1. the ORIGINAL — the file's text, in both languages;
 *   2. the PUBLISHED override, when one exists;
 *   3. the draft, which never leaves the browser until "publish".
 *
 * The cohort's own settings (registration switches, seat override, hero copy,
 * about paragraph, questions) ride along as a fourth block, null when no
 * cohort is published yet — the editor then says so instead of offering
 * switches that have nothing to switch.
 *
 * @see BR-31, BR-36 · PRD §9.1, §9.18 · D-114
 */
final class LandingEditor extends ViewModel
{
    /** Sections whose cards come from the programme, edited on its own screen. */
    private const PROGRAM_SOURCED = ['about', 'goals', 'audience', 'certificates'];

    /** Sections whose content comes from the cohort's schedule and staff. */
    private const COHORT_SOURCED = ['weeks', 'timeline', 'trainers'];

    /**
     * @param  array<string, array{ar: string|null, en: string|null}>  $published
     * @param  array<string, string>  $urls
     */
    public static function from(
        LandingCatalog $catalog,
        array $published,
        ?Cohort $cohort,
        ?LandingSetting $setting,
        array $urls,
    ): self {
        return new self([
            'hasCohort' => $cohort !== null,
            'payload' => [
                'sections' => self::sections($catalog, $urls),
                'published' => (object) $published,
                'settings' => $cohort === null ? null : self::settings($setting),
                'faq' => $cohort === null ? null : self::faq($setting),
                'seatsComputed' => $cohort?->seatsRemaining() ?? 0,
                'programName' => (string) ($cohort?->program?->getAttribute('name_ar') ?? ''),
                'cohortId' => $cohort === null ? null : (string) $cohort->getKey(),
                'max' => LandingCatalog::MAX_LENGTH,
                'urls' => $urls,
                'i18n' => self::messages(),
            ],
        ]);
    }

    /**
     * The published layer alone — what the editor replaces its own copy with
     * after a publish or a reset, without redrawing the sections.
     *
     * @param  array<string, array{ar: string|null, en: string|null}>  $published
     * @return array<string, mixed>
     */
    public static function publishedState(array $published, ?Cohort $cohort, ?LandingSetting $setting): array
    {
        return [
            'published' => (object) $published,
            'settings' => $cohort === null ? null : self::settings($setting),
            'faq' => $cohort === null ? null : self::faq($setting),
            'seatsComputed' => $cohort?->seatsRemaining() ?? 0,
        ];
    }

    /**
     * @param  array<string, string>  $urls
     * @return list<array<string, mixed>>
     */
    private static function sections(LandingCatalog $catalog, array $urls): array
    {
        $sections = [];

        foreach ($catalog->sections() as $section) {
            $key = $section['key'];
            $groups = [];

            foreach ($section['groups'] as $group) {
                $groups[] = [
                    'id' => $key.'.'.$group['id'],
                    'label' => __('admin.landing_editor.groups.'.$group['id']),
                    'hint' => self::optional('admin.landing_editor.group_hints.'.$group['id']),
                    'collapsed' => $group['collapsed'],
                    'setting' => $group['setting'] ? $group['id'] : null,
                    'fields' => array_map(
                        static fn (string $field): array => self::field($catalog, $field),
                        $group['fields'],
                    ),
                ];
            }

            $source = in_array($key, self::PROGRAM_SOURCED, true) ? 'programs'
                : (in_array($key, self::COHORT_SOURCED, true) ? 'cohorts' : null);

            $sections[] = [
                'key' => $key,
                'label' => __('admin.landing_editor.sections.'.$key),
                'hint' => self::optional('admin.landing_editor.section_hints.'.$key),
                'anchor' => $section['anchor'],
                'source' => $source === null ? null : [
                    'label' => __('admin.landing_editor.source.'.$source),
                    'href' => $urls[$source] ?? null,
                ],
                'groups' => $groups,
            ];
        }

        return $sections;
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(LandingCatalog $catalog, string $key): array
    {
        $name = substr($key, strlen(LandingCatalog::GROUP) + 1);
        $role = self::role($name);
        $forms = [];
        $vars = [];
        $defaults = [];

        foreach (LandingCatalog::LOCALES as $locale) {
            $default = $catalog->defaultOf($key, $locale);
            $defaults[$locale] = $default;
            $vars[$locale] = LandingCatalog::placeholders($default);

            $parsed = LandingCatalog::pluralForms($default);
            $forms[$locale] = $parsed === null ? null : array_map(
                static fn (array $form, int $index): array => [
                    'marker' => $form['marker'],
                    'label' => self::formLabel($form['marker'], $index),
                ],
                $parsed,
                array_keys($parsed),
            );
        }

        return [
            'key' => $key,
            'name' => $name,
            'role' => $role === null ? null : __('admin.landing_editor.roles.'.$role),
            'ar' => $defaults['ar'],
            'en' => $defaults['en'],
            'multiline' => $catalog->isMultiline($key),
            'forms' => $forms,
            'vars' => $vars,
        ];
    }

    /**
     * What a field is, read off the last part of its name. A name that says
     * nothing certain gets no label at all: the key beside it is more honest
     * than a guessed title.
     */
    private static function role(string $name): ?string
    {
        $last = (string) substr((string) strrchr('.'.$name, '.'), 1);

        return match (true) {
            str_starts_with($last, 'aria_'), in_array($last, ['canvas_label', 'brand_alt', 'add_a', 'add_b', 'skip_to_content', 'page_sections', 'page_trail', 'open_menu', 'close_menu'], true) => 'a11y',
            str_starts_with($last, 'empty_') => 'empty',
            str_starts_with($last, 'chip_') => 'chip',
            str_starts_with($last, 'trust_') => 'stat',
            str_starts_with($last, 'q_') => 'question',
            str_ends_with($last, '_choice') => 'counted',
            in_array($last, ['kicker', 'eyebrow'], true) => 'eyebrow',
            $last === 'title' || str_ends_with($last, '_title') => 'title',
            $last === 'lead' => 'lead',
            $last === 'body' || str_ends_with($last, '_body') => 'body',
            str_ends_with($last, 'hint') => 'hint',
            $last === 'note' || str_ends_with($last, '_note') => 'note',
            str_ends_with($last, 'placeholder') => 'placeholder',
            in_array($last, ['register', 'login', 'ask', 'seed', 'clear', 'try_lab', 'go_to_dashboard', 'waitlist_submit'], true),
            str_ends_with($last, '_action') => 'button',
            default => null,
        };
    }

    /** "Singular", "dual", "3 to 10"… — the name of one counted form. */
    private static function formLabel(string $marker, int $index): string
    {
        if (preg_match('/^\{(\d+)\}$/', $marker, $exact) === 1) {
            return match ((int) $exact[1]) {
                0 => __('admin.landing_editor.plural.zero'),
                1 => __('admin.landing_editor.plural.one'),
                2 => __('admin.landing_editor.plural.two'),
                default => __('admin.landing_editor.plural.exact', ['n' => $exact[1]]),
            };
        }

        if (preg_match('/^\[(\d+),\s*(\*|\d+)\]$/', $marker, $range) === 1) {
            return $range[2] === '*'
                ? __('admin.landing_editor.plural.from', ['from' => $range[1]])
                : __('admin.landing_editor.plural.range', ['from' => $range[1], 'to' => $range[2]]);
        }

        return __('admin.landing_editor.plural.form', ['n' => $index + 1]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings(?LandingSetting $setting): array
    {
        $override = $setting?->getAttribute('seats_remaining_override');

        return [
            // Shown, not edited (D-117): the supervisor moves it.
            'is_registration_open' => LandingSetting::switchIsOn($setting),
            'countdown_enabled' => (bool) ($setting?->getAttribute('countdown_enabled') ?? false),
            'seats_override' => is_numeric($override) ? (int) $override : null,
            'hero_title' => (string) ($setting?->getAttribute('hero_title') ?? ''),
            'hero_subtitle' => (string) ($setting?->getAttribute('hero_text') ?? ''),
            'about_body' => (string) ($setting?->getAttribute('about_body') ?? ''),
        ];
    }

    /**
     * The questions exactly as the public page lists them.
     *
     * An entry the seeder wrote carries no key — only a question and an
     * answer — and the old editor skipped every such entry, so the questions a
     * visitor read were the ones nobody could edit. They are listed here with
     * a null key; the first publish gives each one a key of the server's own.
     *
     * @return list<array{key: string|null, question: string, answer: string}>
     */
    private static function faq(?LandingSetting $setting): array
    {
        $faq = $setting?->getAttribute('faq');
        $entries = [];

        foreach (is_array($faq) ? $faq : [] as $row) {
            if (! is_array($row) || (! is_string($row['question'] ?? null) && ! is_string($row['answer'] ?? null))) {
                continue;
            }

            $entries[] = [
                'key' => is_string($row['key'] ?? null) && $row['key'] !== '' ? $row['key'] : null,
                'question' => is_string($row['question'] ?? null) ? $row['question'] : '',
                'answer' => is_string($row['answer'] ?? null) ? $row['answer'] : '',
            ];
        }

        return $entries;
    }

    /**
     * The sentences the editor's script prints on its own: counts, toasts,
     * confirmations and field errors. Counted sentences carry all four Arabic
     * forms so the script can choose, never compose.
     *
     * @return array<string, mixed>
     */
    private static function messages(): array
    {
        $messages = Lang::get('admin.landing_editor.js');

        return array_merge(is_array($messages) ? $messages : [], [
            'errors' => Lang::get('admin.landing_editor.errors'),
            'lang_ar' => __('admin.landing_editor.arabic'),
            'lang_en' => __('admin.landing_editor.english'),
            'publish' => __('admin.landing_editor.publish'),
            'faq_number' => Lang::get('admin.landing_editor.faq_number'),
        ]);
    }

    private static function optional(string $key): ?string
    {
        $value = Lang::has($key) ? __($key) : null;

        return is_string($value) ? $value : null;
    }
}
