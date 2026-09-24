<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\LandingContent;
use App\Models\LandingSetting;
use App\Services\Landing\LandingCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One publish from the landing-page editor: the texts that changed, and — when
 * the editor touched them — this cohort's settings and its question list.
 *
 * TEXTS arrive as a LIST of {key, ar, en}, never as a map keyed by the
 * translation key: the keys themselves contain dots, and a dotted array key is
 * read by the validator as nesting. A language that is empty or equal to the
 * file is stored as NULL, which is what "back to the original" means.
 *
 * Three rules are enforced here and not only in the browser (Art. 5):
 *   - the key must be one of the page's texts; nothing else can be written;
 *   - a text may not drop a live value such as `:count` that its original
 *     carries — the visitor would read ":count seats";
 *   - a counted text keeps the same forms, in the same order, as its original.
 *
 * @see BR-31, BR-36 · PRD §9.1, §9.18 · CONSTITUTION Art. 5 · D-114
 */
final class PublishLandingRequest extends FormRequest
{
    /** The editor's setting names, and the landing_settings column of each. */
    private const SETTING_COLUMNS = [
        'is_registration_open' => 'is_registration_open',
        'countdown_enabled' => 'countdown_enabled',
        'seats_override' => 'seats_remaining_override',
        'hero_title' => 'hero_title',
        'hero_subtitle' => 'hero_text',
        'about_body' => 'about_body',
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('update', LandingContent::class)
            && $user->can('update', new LandingSetting);
    }

    /**
     * Only what was sent is normalised. A flag that is absent stays absent —
     * the publish is a PATCH, so an absent flag means "unchanged", never
     * "false" — and a flag that is not a boolean stays as sent, so the
     * `boolean` rule refuses it instead of it becoming a closed registration.
     */
    protected function prepareForValidation(): void
    {
        $settings = $this->input('settings');

        if (! is_array($settings)) {
            return;
        }

        foreach (['is_registration_open', 'countdown_enabled'] as $flag) {
            if (array_key_exists($flag, $settings)) {
                $bool = filter_var($settings[$flag], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $settings[$flag] = $bool ?? $settings[$flag];
            }
        }

        if (($settings['seats_override'] ?? null) === '') {
            $settings['seats_override'] = null;
        }

        $this->merge(['settings' => $settings]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $catalog = app(LandingCatalog::class);
        $keys = $catalog->keys();

        return [
            'texts' => ['sometimes', 'array', 'max:'.count($keys)],
            'texts.*' => ['array'],
            'texts.*.key' => ['required', 'string', 'distinct', Rule::in($keys)],
            'texts.*.ar' => ['sometimes', 'nullable', 'string', 'max:'.LandingCatalog::MAX_LENGTH],
            'texts.*.en' => ['sometimes', 'nullable', 'string', 'max:'.LandingCatalog::MAX_LENGTH],

            // A PATCH: each setting is optional, and only the ones sent change.
            'settings' => ['sometimes', 'array', 'min:1'],
            'settings.is_registration_open' => ['sometimes', 'boolean'],
            'settings.countdown_enabled' => ['sometimes', 'boolean'],
            'settings.seats_override' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'settings.hero_title' => ['sometimes', 'nullable', 'string', 'max:300'],
            'settings.hero_subtitle' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'settings.about_body' => ['sometimes', 'nullable', 'string', 'max:5000'],

            // The cohort the editor was showing. When it is no longer the one
            // the page features, the settings are refused rather than written
            // to a cohort the editor never displayed.
            'cohort_id' => ['sometimes', 'nullable', 'string', 'max:36'],

            'faq' => ['sometimes', 'array', 'max:100'],
            'faq.*' => ['array'],
            'faq.*.key' => ['nullable', 'string', 'max:64'],
            'faq.*.question' => ['required', 'string', 'max:300'],
            'faq.*.answer' => ['required', 'string', 'max:3000'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('texts') && ! $this->has('settings') && ! $this->has('faq')) {
                    $validator->errors()->add('texts', __('admin.landing_editor.errors.nothing'));

                    return;
                }

                $catalog = app(LandingCatalog::class);
                $texts = $this->input('texts');

                if (! is_array($texts)) {
                    return;
                }

                foreach ($texts as $index => $row) {
                    $key = is_array($row) ? ($row['key'] ?? null) : null;

                    if (! is_string($key) || ! $catalog->has($key)) {
                        continue;
                    }

                    if (! array_key_exists('ar', $row) && ! array_key_exists('en', $row)) {
                        $validator->errors()->add("texts.{$index}", __('admin.landing_editor.errors.nothing'));

                        continue;
                    }

                    foreach (LandingCatalog::LOCALES as $locale) {
                        if (! array_key_exists($locale, $row)) {
                            continue;
                        }

                        $value = $row[$locale];

                        foreach ($catalog->issues($key, $locale, is_string($value) ? $value : null) as $issue) {
                            $validator->errors()->add(
                                "texts.{$index}.{$locale}",
                                __('admin.landing_editor.errors.'.$issue, [
                                    'max' => LandingCatalog::MAX_LENGTH,
                                    'vars' => implode(' ', $catalog->missingPlaceholders($key, $locale, is_string($value) ? $value : '')),
                                ]),
                            );
                        }
                    }
                }
            },
        ];
    }

    /**
     * The texts to publish, each SENT language already reduced to what is
     * stored: NULL for empty or unchanged-from-file. A language that was not
     * sent is absent here and keeps its published value — so an edit to the
     * Arabic never reverts somebody else's newer English, and the reverse.
     *
     * @return array<string, array<string, string|null>>
     */
    public function texts(): array
    {
        $catalog = app(LandingCatalog::class);
        $texts = [];

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->validated('texts', []);

        foreach ($rows as $row) {
            $key = (string) $row['key'];
            $entry = [];

            foreach (LandingCatalog::LOCALES as $locale) {
                if (array_key_exists($locale, $row)) {
                    $value = $row[$locale];
                    $entry[$locale] = $catalog->storable($key, $locale, is_string($value) ? $value : null);
                }
            }

            $texts[$key] = $entry;
        }

        return $texts;
    }

    /** The cohort the editor was showing, when it said. */
    public function cohortId(): ?string
    {
        $id = $this->validated('cohort_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The settings that were SENT, in column names, or null when none were.
     * Everything not sent keeps its stored value (a PATCH): a draft that only
     * touched the headline can never reopen or close the registration. The
     * hero subtitle is the existing `hero_text` column, which the public page
     * already prints under the headline.
     *
     * @return array<string, mixed>|null
     */
    public function settingColumns(): ?array
    {
        if (! $this->has('settings')) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = $this->validated('settings');
        $columns = [];

        foreach (self::SETTING_COLUMNS as $field => $column) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            $columns[$column] = match ($field) {
                'is_registration_open', 'countdown_enabled' => (bool) $value,
                'seats_override' => $value === null ? null : (int) $value,
                default => self::clean($value),
            };
        }

        return $columns === [] ? null : $columns;
    }

    /**
     * The question list as the editor ordered it, or null when untouched.
     * Keys are only proposals; the controller keeps a key it already stored and
     * generates every other one.
     *
     * @return list<array{key: string|null, question: string, answer: string}>|null
     */
    public function faq(): ?array
    {
        if (! $this->has('faq')) {
            return null;
        }

        $entries = [];

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->validated('faq', []);

        foreach ($rows as $row) {
            $entries[] = [
                'key' => is_string($row['key'] ?? null) ? $row['key'] : null,
                'question' => trim((string) $row['question']),
                'answer' => trim((string) $row['answer']),
            ];
        }

        return $entries;
    }

    private static function clean(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }
}
