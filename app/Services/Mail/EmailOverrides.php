<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\EmailTemplateOverride;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\QueryException;
use Illuminate\Translation\Translator as ConcreteTranslator;

/**
 * The published e-mail copy, as the letters read it — or, for the one request
 * that renders the editor's preview, the editor's draft instead.
 *
 * One instance per request (a scoped binding), so the table is read at most
 * once however many letters the request renders.
 *
 * A database that cannot be read degrades to the defaults in the lang files
 * rather than to an error: the original copy is always a correct letter, and a
 * letter that fails on its own wording helps nobody (Constitution, Article 7).
 *
 * @see BR-31, BR-36 · PRD §9.16, §9.18 · D-114, D-136
 */
final class EmailOverrides
{
    /** @var array<string, array{ar: string|null, en: string|null}>|null */
    private ?array $published = null;

    /** @var array<string, array{ar: string|null, en: string|null}>|null */
    private ?array $preview = null;

    public function __construct(private readonly Translator $translator) {}

    /**
     * Every published override, keyed by its full translation key.
     *
     * @return array<string, array{ar: string|null, en: string|null}>
     */
    public function published(): array
    {
        if ($this->published !== null) {
            return $this->published;
        }

        try {
            $rows = EmailTemplateOverride::query()->get(['key', 'ar', 'en']);
        } catch (QueryException) {
            return $this->published = [];
        }

        $published = [];

        foreach ($rows as $row) {
            $published[(string) $row->getAttribute('key')] = [
                'ar' => self::text($row->getAttribute('ar')),
                'en' => self::text($row->getAttribute('en')),
            ];
        }

        return $this->published = $published;
    }

    /**
     * The overrides of one language, keyed by the name INSIDE the emails group
     * (`welcome.subject`), which is how the loader addresses the file.
     *
     * @return array<string, string>
     */
    public function forLocale(string $locale): array
    {
        $prefix = EmailTemplates::GROUP.'.';
        $lines = [];

        foreach ($this->preview ?? $this->published() as $key => $values) {
            $value = $values[$locale] ?? null;

            if (is_string($value) && $value !== '' && str_starts_with($key, $prefix)) {
                $lines[substr($key, strlen($prefix))] = $value;
            }
        }

        return $lines;
    }

    /**
     * Render the rest of this request with the editor's draft instead of the
     * published copy. The draft is the WHOLE state (published plus edits), so a
     * text the editor cleared falls back to the file here too.
     *
     * Nothing is written: the draft lives in this object and dies with the
     * request.
     *
     * @param  array<string, array{ar: string|null, en: string|null}>  $state
     */
    public function preview(array $state): void
    {
        $this->preview = $state;
        $this->flush();
    }

    /**
     * Back to the published copy once the preview letter has been rendered, so
     * nothing later in the same process can mistake the draft for the letter.
     */
    public function endPreview(): void
    {
        $this->preview = null;
        $this->flush();
    }

    /** Forget what was read, after a save or a reset changed the table. */
    public function forget(): void
    {
        $this->published = null;
        $this->flush();
    }

    /**
     * The translator keeps every group it loaded for the rest of the request;
     * dropping them makes the next __('emails.*') read the new state.
     */
    private function flush(): void
    {
        if ($this->translator instanceof ConcreteTranslator) {
            $this->translator->setLoaded([]);
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
