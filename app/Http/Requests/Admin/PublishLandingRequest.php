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
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('update', LandingContent::class)
            && $user->can('update', new LandingSetting);
    }

    protected function prepareForValidation(): void
    {
        $settings = $this->input('settings');

        if (is_array($settings)) {
            foreach (['is_registration_open', 'countdown_enabled'] as $flag) {
                $settings[$flag] = filter_var($settings[$flag] ?? false, FILTER_VALIDATE_BOOLEAN);
            }

            $override = $settings['seats_override'] ?? null;
            $settings['seats_override'] = $override === '' ? null : $override;

            $this->merge(['settings' => $settings]);
        }
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
            'texts.*.ar' => ['present', 'nullable', 'string', 'max:'.LandingCatalog::MAX_LENGTH],
            'texts.*.en' => ['present', 'nullable', 'string', 'max:'.LandingCatalog::MAX_LENGTH],

            'settings' => ['sometimes', 'array'],
            'settings.is_registration_open' => ['required_with:settings', 'boolean'],
            'settings.countdown_enabled' => ['required_with:settings', 'boolean'],
            'settings.seats_override' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'settings.hero_title' => ['nullable', 'string', 'max:300'],
            'settings.hero_subtitle' => ['nullable', 'string', 'max:2000'],
            'settings.about_body' => ['nullable', 'string', 'max:5000'],

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

                    foreach (LandingCatalog::LOCALES as $locale) {
                        $value = $row[$locale] ?? null;

                        foreach ($catalog->issues($key, $locale, is_string($value) ? $value : null) as $issue) {
                            $validator->errors()->add(
                                "texts.{$index}.{$locale}",
                                __('admin.landing_editor.errors.'.$issue, [
                                    'max' => LandingCatalog::MAX_LENGTH,
                                    'vars' => implode(' ', LandingCatalog::placeholders($catalog->defaultOf($key, $locale))),
                                ]),
                            );
                        }
                    }
                }
            },
        ];
    }

    /**
     * The texts to publish, each language already reduced to what is stored:
     * NULL for empty or unchanged-from-file.
     *
     * @return array<string, array{ar: string|null, en: string|null}>
     */
    public function texts(): array
    {
        $catalog = app(LandingCatalog::class);
        $texts = [];

        /** @var list<array{key: string, ar: string|null, en: string|null}> $rows */
        $rows = $this->validated('texts', []);

        foreach ($rows as $row) {
            $texts[$row['key']] = [
                'ar' => $catalog->storable($row['key'], 'ar', $row['ar']),
                'en' => $catalog->storable($row['key'], 'en', $row['en']),
            ];
        }

        return $texts;
    }

    /**
     * The cohort settings in column names, or null when the editor left them
     * untouched. The hero subtitle is the existing `hero_text` column, which
     * the public page already prints under the headline.
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

        return [
            'is_registration_open' => (bool) $data['is_registration_open'],
            'countdown_enabled' => (bool) $data['countdown_enabled'],
            'seats_remaining_override' => isset($data['seats_override']) ? (int) $data['seats_override'] : null,
            'hero_title' => self::clean($data['hero_title'] ?? null),
            'hero_text' => self::clean($data['hero_subtitle'] ?? null),
            'about_body' => self::clean($data['about_body'] ?? null),
        ];
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
