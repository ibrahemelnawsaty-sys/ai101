<?php

declare(strict_types=1);

namespace App\Services\Landing;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Translation\FileLoader;

/**
 * The landing page as its editor sees it: one section per section of the
 * page, one group per block of text inside it, and the default of every text
 * in both languages.
 *
 * WHY A WRITTEN MAP AND NOT A DERIVED ONE
 * A list derived from lang/ar/landing.php knows the keys and not the page: it
 * would put the hero's countdown labels beside the footer's rights line and
 * ask the editor to guess which is which. The map below says where each text
 * sits, and it is the one place to update when the page gains a sentence.
 *
 * NOTHING GETS LOST WHEN THE MAP FALLS BEHIND
 * Any key the file holds and the map does not name is collected into the
 * "other" section, so every visitor-facing string stays editable even before
 * this file is updated (the same guarantee the Sajaya editor gives).
 *
 * The defaults are read straight from the files through a private FileLoader,
 * never through the application translator: the translator already carries the
 * published overrides, and "the original" must mean the file.
 *
 * Four groups are not texts at all but per-cohort settings (the registration
 * switches, the hero copy written for this cohort, the about paragraph and the
 * question list). They are named here so the editor can place them inside the
 * section they change; their values live on `landing_settings`.
 *
 * @see BR-31, BR-36 · PRD §9.1, §9.18 · D-114
 */
final class LandingCatalog
{
    /** The translation group every landing text belongs to. */
    public const GROUP = 'landing';

    /** The languages the editor writes. Arabic first: it is the page's language. */
    public const LOCALES = ['ar', 'en'];

    /** The longest text a column accepts, checked before the database sees it. */
    public const MAX_LENGTH = 4000;

    /** Past this length a text is edited in a multi-line box rather than a line. */
    private const MULTILINE_AT = 68;

    /** The section every unmapped key falls into. */
    public const OTHER_SECTION = 'other';

    /** Settings groups: values that live on landing_settings, not in the lang files. */
    public const SETTING_GROUPS = ['registration', 'hero_copy', 'about_copy', 'faq_list'];

    /**
     * section => [anchor, groups]. A group is [id, fields, flags]; flags are
     * `collapsed` (folded until opened) and `setting` (a landing_settings block).
     *
     * @var array<string, array{anchor: string, groups: list<array{0: string, 1: list<string>, 2?: array<string, bool>}>}>
     */
    private const SECTIONS = [
        'registration' => ['anchor' => 'top', 'groups' => [
            ['registration', [], ['setting' => true]],
        ]],
        'nav' => ['anchor' => 'top', 'groups' => [
            ['links', ['nav.about', 'nav.lab', 'nav.learn', 'nav.goals', 'nav.timeline', 'nav.certificates', 'nav.faq']],
            ['entry', ['nav.login', 'nav.register']],
            ['a11y', ['meta.brand_alt', 'meta.skip_to_content', 'meta.page_sections', 'meta.page_trail', 'meta.open_menu', 'meta.close_menu'], ['collapsed' => true]],
        ]],
        'hero' => ['anchor' => 'top', 'groups' => [
            ['hero_copy', [], ['setting' => true]],
            ['buttons', ['hero.register', 'hero.go_to_dashboard', 'hero.try_lab']],
            ['badge', ['hero.registration_open']],
            ['chips', ['facts.chip_weeks', 'facts.chip_sessions', 'facts.chip_hours', 'facts.chip_remote', 'facts.chip_certificates']],
            ['countdown', ['hero.countdown_title', 'hero.days', 'hero.hours', 'hero.minutes', 'hero.seconds']],
            ['closed', ['hero.registration_closed_title', 'hero.registration_closed_body', 'hero.waitlist_email', 'hero.waitlist_email_placeholder', 'hero.waitlist_submit', 'hero.waitlist_done']],
        ]],
        'trust' => ['anchor' => 'trust', 'groups' => [
            ['labels', ['facts.trust_weeks', 'facts.trust_sessions', 'facts.trust_hours', 'facts.trust_points', 'facts.trust_attendance']],
        ]],
        'about' => ['anchor' => 'about', 'groups' => [
            ['about_copy', [], ['setting' => true]],
            ['header', ['headings.about.kicker']],
        ]],
        'lab' => ['anchor' => 'lab', 'groups' => [
            ['header', ['lab.kicker', 'lab.title', 'lab.lead']],
            ['controls', ['lab.class_prompt', 'lab.class_a', 'lab.class_b', 'lab.accuracy', 'lab.examples', 'lab.epochs', 'lab.seed', 'lab.clear']],
            ['notes', ['lab.hint', 'lab.note', 'lab.unavailable']],
            ['hud', ['sim.js.model_accuracy', 'sim.js.model_stage_start', 'sim.js.model_stage_learn', 'sim.js.model_stage_tune', 'sim.js.model_stage_settle', 'sim.js.model_stage_ready'], ['collapsed' => true]],
            ['a11y', ['lab.canvas_label', 'lab.add_a', 'lab.add_b'], ['collapsed' => true]],
        ]],
        'goals' => ['anchor' => 'goals', 'groups' => [
            ['header', ['headings.goals.kicker', 'headings.goals.title', 'headings.goals.lead']],
            ['empty', ['states.empty_goals']],
        ]],
        'audience' => ['anchor' => 'audience', 'groups' => [
            ['header', ['headings.audience.kicker', 'headings.audience.title', 'headings.audience.lead']],
            ['empty', ['states.empty_audience']],
        ]],
        'weeks' => ['anchor' => 'learn', 'groups' => [
            ['header', ['headings.weeks.kicker', 'headings.weeks.title', 'sections.deck_hint']],
            ['week_meta', ['sections.sessions_choice', 'sections.date_range', 'sections.week_progress', 'sections.sessions_count']],
            ['empty', ['states.empty_weeks']],
        ]],
        'timeline' => ['anchor' => 'timeline', 'groups' => [
            ['header', ['headings.timeline.kicker', 'headings.timeline.title', 'headings.timeline.lead']],
            ['stages', ['headings.timeline.registration_closes', 'headings.timeline.intro_session', 'headings.timeline.training_weeks', 'headings.timeline.final_project', 'headings.timeline.closing_session']],
            ['empty', ['states.empty_timeline']],
        ]],
        'certificates' => ['anchor' => 'certs', 'groups' => [
            ['header', ['headings.certificates.kicker', 'headings.certificates.title', 'headings.certificates.lead']],
            ['flip', ['sections.certificate_flip_hint']],
            ['empty', ['states.empty_certificates']],
        ]],
        'sim' => ['anchor' => 'sim-intro', 'groups' => [
            ['header', ['sim.kicker', 'sim.title', 'sim.lead']],
            ['questions', ['sim.q_sessions', 'sim.q_tasks', 'sim.q_project', 'sim.explainer']],
            ['gates', ['sim.gate_attendance', 'sim.gate_score', 'sim.min_attendance', 'sim.pass_score', 'sim.tag_attendance', 'sim.tag_score']],
            ['verdicts', ['sim.js.verdict_pass_title', 'sim.js.verdict_pass_body', 'sim.js.verdict_none_title', 'sim.js.verdict_none_body', 'sim.js.verdict_attendance_title', 'sim.js.verdict_attendance_body', 'sim.js.verdict_score_title', 'sim.js.verdict_score_body']],
            ['live_notes', ['sim.js.spare_none', 'sim.js.spare_some', 'sim.js.need_more', 'sim.js.score_breakdown', 'sim.js.score_above', 'sim.js.score_below']],
            ['counting', ['sim.js.session_one', 'sim.js.session_two', 'sim.js.session_few', 'sim.js.session_many']],
            ['a11y', ['sim.aria_sessions', 'sim.aria_tasks', 'sim.aria_project'], ['collapsed' => true]],
        ]],
        'trainers' => ['anchor' => 'trainers', 'groups' => [
            ['header', ['headings.trainers.kicker', 'headings.trainers.title', 'headings.trainers.lead']],
            ['empty', ['states.empty_trainers', 'sections.trainers_note']],
        ]],
        'faq' => ['anchor' => 'faq', 'groups' => [
            ['faq_list', [], ['setting' => true]],
            ['header', ['headings.faq.kicker', 'headings.faq.title', 'sections.faq_contact']],
            ['empty', ['states.empty_faq']],
        ]],
        'final' => ['anchor' => 'final', 'groups' => [
            ['header', ['headings.final.eyebrow', 'headings.final.title', 'headings.final.body']],
            ['buttons', ['final.register', 'final.ask']],
            ['seats', ['sections.seats_choice', 'hero.seats_of', 'final.seats_booked_suffix', 'hero.seats_progress', 'hero.seats_label']],
        ]],
        'footer' => ['anchor' => 'site-footer', 'groups' => [
            ['columns', ['footer.quick_links', 'footer.contact']],
            ['footer_links', ['footer.terms', 'footer.privacy']],
            ['rights', ['footer.rights']],
            ['whatsapp', ['footer.whatsapp', 'footer.whatsapp_message']],
        ]],
        'states' => ['anchor' => 'top', 'groups' => [
            ['page_states', ['states.loading_label', 'states.empty_page_title', 'states.empty_page_body', 'states.empty_page_action', 'states.error_title', 'states.error_body', 'states.error_action', 'states.empty_trust']],
        ]],
        self::OTHER_SECTION => ['anchor' => 'top', 'groups' => [
            ['waitlist', ['waitlist.acknowledged', 'waitlist.already_registered']],
            ['legal', ['legal.terms_title', 'legal.privacy_title', 'legal.updated_at', 'legal.back_home', 'legal.empty_title', 'legal.empty_body', 'legal.error_title', 'legal.error_body']],
        ]],
    ];

    /** @var array<string, array<string, string>> locale => full key => default */
    private array $defaults = [];

    /** @var list<array{key: string, anchor: string, groups: list<array{id: string, fields: list<string>, collapsed: bool, setting: bool}>}>|null */
    private ?array $resolved = null;

    private readonly FileLoader $files;

    public function __construct(?FileLoader $files = null)
    {
        $this->files = $files ?? new FileLoader(new Filesystem, [lang_path()]);
    }

    /**
     * Every default text of one language, flattened to full keys:
     * `landing.hero.register` => the sentence in lang/{locale}/landing.php.
     *
     * @return array<string, string>
     */
    public function defaults(string $locale): array
    {
        if (! isset($this->defaults[$locale])) {
            $flat = [];

            foreach (Arr::dot($this->files->load($locale, self::GROUP)) as $name => $value) {
                if (is_string($value)) {
                    $flat[self::GROUP.'.'.$name] = $value;
                }
            }

            $this->defaults[$locale] = $flat;
        }

        return $this->defaults[$locale];
    }

    /** The file's text for one key, or '' when the language has none. */
    public function defaultOf(string $key, string $locale): string
    {
        return $this->defaults($locale)[$key] ?? '';
    }

    /** Whether the key is one of the page's texts. Anything else is refused. */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->defaults('ar'));
    }

    /**
     * Every editable key, in the order the editor shows them.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->sections() as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['fields'] as $key) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * The keys that belong to one section, or null for a section that does
     * not exist.
     *
     * @return list<string>|null
     */
    public function sectionKeys(string $section): ?array
    {
        foreach ($this->sections() as $candidate) {
            if ($candidate['key'] === $section) {
                $keys = [];

                foreach ($candidate['groups'] as $group) {
                    array_push($keys, ...$group['fields']);
                }

                return $keys;
            }
        }

        return null;
    }

    /**
     * The map applied to the files: fields the files no longer carry are
     * dropped, and fields the map does not name are gathered into "other".
     *
     * @return list<array{key: string, anchor: string, groups: list<array{id: string, fields: list<string>, collapsed: bool, setting: bool}>}>
     */
    public function sections(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $known = $this->defaults('ar');
        $taken = [];
        $sections = [];

        foreach (self::SECTIONS as $key => $spec) {
            $groups = [];

            foreach ($spec['groups'] as $group) {
                [$id, $fields] = $group;
                $flags = $group[2] ?? [];
                $setting = (bool) ($flags['setting'] ?? false);
                $present = [];

                foreach ($fields as $name) {
                    $full = self::GROUP.'.'.$name;

                    if (array_key_exists($full, $known) && ! isset($taken[$full])) {
                        $taken[$full] = true;
                        $present[] = $full;
                    }
                }

                if ($present !== [] || $setting) {
                    $groups[] = [
                        'id' => $id,
                        'fields' => $present,
                        'collapsed' => (bool) ($flags['collapsed'] ?? false),
                        'setting' => $setting,
                    ];
                }
            }

            $sections[$key] = ['key' => $key, 'anchor' => $spec['anchor'], 'groups' => $groups];
        }

        $leftovers = array_values(array_filter(
            array_keys($known),
            static fn (string $full): bool => ! isset($taken[$full]),
        ));

        if ($leftovers !== []) {
            $other = $sections[self::OTHER_SECTION] ?? ['key' => self::OTHER_SECTION, 'anchor' => 'top', 'groups' => []];
            $other['groups'][] = [
                'id' => 'unmapped',
                'fields' => $leftovers,
                'collapsed' => false,
                'setting' => false,
            ];
            $sections[self::OTHER_SECTION] = $other;
        }

        $resolved = [];

        foreach ($sections as $section) {
            if ($section['groups'] !== []) {
                $resolved[] = $section;
            }
        }

        return $this->resolved = $resolved;
    }

    /**
     * The live values in a text: `:count`, `:program` and the like. Removing
     * one turns "12 seats" into ":count seats" on the page, so a text that
     * drops one is refused.
     *
     * @return list<string>
     */
    public static function placeholders(string $text): array
    {
        preg_match_all('/:([A-Za-z_]+)/', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * The counted forms of a plural text, each with its range marker:
     * `{1} one week|[2,*] :count weeks` => [['{1}', 'one week'], ['[2,*]', ':count weeks']].
     * Null for a text that is not a plural.
     *
     * @return list<array{marker: string, text: string}>|null
     */
    public static function pluralForms(string $text): ?array
    {
        if (! str_contains($text, '|')) {
            return null;
        }

        $forms = [];

        foreach (explode('|', $text) as $segment) {
            if (preg_match('/^\s*(\{[^}]*\}|\[[^\]]*\])\s*(.*)$/su', $segment, $match) === 1) {
                $forms[] = ['marker' => $match[1], 'text' => $match[2]];
            } else {
                $forms[] = ['marker' => '', 'text' => trim($segment)];
            }
        }

        return $forms;
    }

    /** Whether the text is long enough to be edited as a paragraph. */
    public function isMultiline(string $key): bool
    {
        return max(
            mb_strlen($this->defaultOf($key, 'ar')),
            mb_strlen($this->defaultOf($key, 'en')),
        ) > self::MULTILINE_AT;
    }

    /**
     * What is wrong with an edited text, as error codes the editor and the
     * FormRequest both translate: `too_long`, `placeholders`, `plural`.
     * An empty value is never an error — it means "back to the original".
     *
     * @return list<string>
     */
    public function issues(string $key, string $locale, ?string $value): array
    {
        $text = trim((string) $value);

        if ($text === '') {
            return [];
        }

        $issues = [];
        $default = $this->defaultOf($key, $locale);

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $issues[] = 'too_long';
        }

        $expected = self::pluralForms($default);
        $given = self::pluralForms($text);

        if ($expected !== null
            && ($given === null || array_column($given, 'marker') !== array_column($expected, 'marker'))) {
            $issues[] = 'plural';
        }

        if ($this->missingPlaceholders($key, $locale, $text) !== []) {
            $issues[] = 'placeholders';
        }

        return $issues;
    }

    /**
     * The live values the edited text dropped. A counted text is checked form
     * by form: ":count" kept in the "11 and above" form does not excuse its
     * loss from the "3 to 10" form, where the visitor would read the noun with
     * no number in front of it.
     *
     * @return list<string>
     */
    public function missingPlaceholders(string $key, string $locale, string $value): array
    {
        $default = $this->defaultOf($key, $locale);
        $expected = self::pluralForms($default);
        $given = self::pluralForms(trim($value));

        if ($expected === null || $given === null || count($given) !== count($expected)) {
            return array_values(array_diff(self::placeholders($default), self::placeholders($value)));
        }

        $missing = [];

        foreach ($expected as $index => $form) {
            array_push($missing, ...array_diff(self::placeholders($form['text']), self::placeholders($given[$index]['text'])));
        }

        return array_values(array_unique($missing));
    }

    /**
     * The value to store for one language: NULL when it is empty or the same
     * as the file, so the text keeps following the file.
     */
    public function storable(string $key, string $locale, ?string $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' || $text === $this->defaultOf($key, $locale) ? null : $text;
    }
}
