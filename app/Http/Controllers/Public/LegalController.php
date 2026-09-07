<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Terms of use and privacy policy.
 *
 * Both documents are delivered as structured data — headings, paragraphs and
 * list items — never as raw HTML, so nothing on these pages is ever rendered
 * unescaped (CONSTITUTION Art. 24). The Arabic text itself lives in
 * lang/ar/landing.php like every other string on the platform (Art. 15).
 *
 * @see BR-31 · PRD §9.2.1, §12.6 · CONSTITUTION Art. 15, Art. 17, Art. 24
 */
final class LegalController extends Controller
{
    public function terms(): View
    {
        return view('public.terms', [
            'state' => 'ok',
            'document' => $this->document('terms'),
        ]);
    }

    public function privacy(): View
    {
        return view('public.privacy', [
            'state' => 'ok',
            'document' => $this->document('privacy'),
        ]);
    }

    /**
     * Read one legal document out of the language files.
     *
     * `landing.legal.{key}.sections` is an array of sections, each with a
     * heading and any number of paragraphs and list items. A document the
     * translator has not written yet comes back with no sections, and the
     * template renders its empty state rather than a blank page (Art. 17).
     *
     * @return array{updated_at: string|null, sections: list<array<string, mixed>>}
     */
    private function document(string $key): array
    {
        $sections = __('landing.legal.'.$key.'.sections');
        $updatedAt = __('landing.legal.'.$key.'.updated_at');

        return [
            'updated_at' => is_string($updatedAt) && ! str_starts_with($updatedAt, 'landing.')
                ? $updatedAt
                : null,
            'sections' => is_array($sections) ? $this->normalise($sections) : [],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $sections
     * @return list<array<string, mixed>>
     */
    private function normalise(array $sections): array
    {
        $clean = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $clean[] = [
                'heading' => isset($section['heading']) && is_string($section['heading'])
                    ? $section['heading']
                    : '',
                'paragraphs' => isset($section['paragraphs']) && is_array($section['paragraphs'])
                    ? array_values(array_filter($section['paragraphs'], 'is_string'))
                    : [],
                'items' => isset($section['items']) && is_array($section['items'])
                    ? array_values(array_filter($section['items'], 'is_string'))
                    : [],
            ];
        }

        return $clean;
    }
}
