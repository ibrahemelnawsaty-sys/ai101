<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\LandingContent;
use App\Services\Landing\LandingCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The editor's draft, sent to be rendered — never to be stored.
 *
 * The draft arrives as one JSON string (`state`) because the preview frame is
 * the target of an ordinary form post, not of a script: the page it returns
 * must be the real landing page with its own scripts and its own nonce.
 *
 * Nothing here is trusted to be well formed. The accessors below rebuild the
 * draft from scratch: only keys the catalogue knows, only strings, only up to
 * the same lengths a publish accepts. Anything else is dropped rather than
 * refused, because a preview that errors on a half-typed field is a preview
 * nobody can use — the refusal belongs to the publish (PublishLandingRequest).
 *
 * @see BR-31 · PRD §9.1, §9.18 · CONSTITUTION Art. 5 · D-114
 */
final class PreviewLandingRequest extends FormRequest
{
    /** A draft is a few hundred short texts; this is several times that. */
    private const STATE_MAX_BYTES = 600000;

    private const FAQ_MAX = 100;

    /** @var array<string, mixed>|null */
    private ?array $decoded = null;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', LandingContent::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'state' => ['nullable', 'string', 'max:'.self::STATE_MAX_BYTES],
            'lang' => ['nullable', 'string', Rule::in(LandingCatalog::LOCALES)],
        ];
    }

    /** The language the preview renders in. Arabic unless English was asked. */
    public function lang(): string
    {
        $lang = $this->validated('lang');

        return is_string($lang) ? $lang : LandingCatalog::LOCALES[0];
    }

    /**
     * The whole text state to render — published plus draft — or null when the
     * frame was opened without one, which renders the published page.
     *
     * @return array<string, array{ar: string|null, en: string|null}>|null
     */
    public function texts(LandingCatalog $catalog): ?array
    {
        $state = $this->state();

        if ($state === null || ! is_array($state['texts'] ?? null)) {
            return null;
        }

        $texts = [];

        foreach ($state['texts'] as $key => $values) {
            if (! is_string($key) || ! $catalog->has($key) || ! is_array($values)) {
                continue;
            }

            $texts[$key] = [
                'ar' => self::text($values['ar'] ?? null, LandingCatalog::MAX_LENGTH),
                'en' => self::text($values['en'] ?? null, LandingCatalog::MAX_LENGTH),
            ];
        }

        return $texts;
    }

    /**
     * Draft cohort settings in column names, or null when there is no draft.
     *
     * @return array<string, mixed>|null
     */
    public function settingColumns(): ?array
    {
        $settings = $this->state()['settings'] ?? null;

        if (! is_array($settings)) {
            return null;
        }

        $override = $settings['seats_override'] ?? null;

        return [
            'is_registration_open' => filter_var($settings['is_registration_open'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'countdown_enabled' => filter_var($settings['countdown_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'seats_remaining_override' => is_numeric($override) ? max(0, min(10000, (int) $override)) : null,
            'hero_title' => self::text($settings['hero_title'] ?? null, 300),
            'hero_text' => self::text($settings['hero_subtitle'] ?? null, 2000),
            'about_body' => self::text($settings['about_body'] ?? null, 5000),
        ];
    }

    /**
     * The draft question list, or null when there is no draft. Empty rows —
     * one the editor has just added — are skipped, as the page would skip them.
     *
     * @return list<array{key: string, question: string, answer: string}>|null
     */
    public function faq(): ?array
    {
        $faq = $this->state()['faq'] ?? null;

        if (! is_array($faq)) {
            return null;
        }

        $entries = [];

        foreach (array_slice(array_values($faq), 0, self::FAQ_MAX) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $question = self::text($row['question'] ?? null, 300);
            $answer = self::text($row['answer'] ?? null, 3000);

            if ($question !== null && $answer !== null) {
                $entries[] = ['key' => 'preview-'.$index, 'question' => $question, 'answer' => $answer];
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function state(): ?array
    {
        if ($this->decoded !== null) {
            return $this->decoded;
        }

        $raw = $this->validated('state');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true, 8);

        return $this->decoded = is_array($decoded) ? $decoded : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
