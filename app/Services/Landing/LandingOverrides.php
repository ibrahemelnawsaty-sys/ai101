<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\LandingContent;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\QueryException;
use Illuminate\Translation\Translator as ConcreteTranslator;

/**
 * The published landing copy, as the public pages read it — or, for the one
 * request that renders the editor's live preview, the editor's draft instead.
 *
 * One instance per request (a scoped binding), so the table is read at most
 * once per page however many texts the page prints.
 *
 * A database that cannot be read degrades to the defaults in the lang files
 * rather than to an error page: the original copy is always a correct page,
 * and a landing page that fails on its own marketing text helps nobody
 * (Constitution, Article 7). The editor itself never goes through this path
 * for writes.
 *
 * @see BR-31, BR-36 · PRD §9.1 · D-114
 */
final class LandingOverrides
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
            $rows = LandingContent::query()->get(['key', 'ar', 'en']);
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
     * The overrides of one language, keyed by the name INSIDE the landing
     * group (`hero.register`), which is how the loader addresses the file.
     *
     * @return array<string, string>
     */
    public function forLocale(string $locale): array
    {
        $prefix = LandingCatalog::GROUP.'.';
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
     * published copy. The draft is the WHOLE state (published plus edits), not
     * a delta, so a text the editor reverted disappears here too.
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
     * Back to the published copy once the preview page has been rendered, so
     * nothing later in the same process can mistake the draft for the page.
     */
    public function endPreview(): void
    {
        $this->preview = null;
        $this->flush();
    }

    /** Forget what was read, after a publish or a reset changed the table. */
    public function forget(): void
    {
        $this->published = null;
        $this->flush();
    }

    /**
     * The translator keeps every group it loaded for the rest of the request;
     * dropping them makes the next __('landing.*') read the new state.
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
