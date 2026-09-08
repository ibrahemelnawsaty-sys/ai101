<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;

/**
 * The two standing public pages: about the centre, and how to reach it.
 *
 * They follow LegalController exactly rather than inventing a second shape for
 * the same job: the copy is delivered as STRUCTURED data — headings, paragraphs
 * and list items — and the template prints it escaped. Nothing on a public page
 * is ever rendered as raw HTML (Article 24).
 *
 * The text lives in lang/{locale}/pages.php (Article 15). That is the same
 * decision the legal pages already made, and its reasoning holds here: this is
 * standing copy about the centre, not per-cohort content that an admin edits
 * between one intake and the next.
 *
 * NOTHING ABOUT THE CENTRE IS INVENTED HERE. The about page states only what
 * the repository already asserts about the platform. Founding dates, graduate
 * numbers, partners and achievements are the centre's to supply, and until it
 * does they are absent rather than guessed — `D-14`.
 *
 * The contact page carries no form. `D-02` is open, no mail provider is
 * approved, and `MAIL_MAILER` discards every message: a form here would accept
 * a visitor's question and silently drop it. The channels it lists instead —
 * WhatsApp and email — leave the platform entirely and arrive with certainty.
 * The moment `D-02` closes, a form becomes a real option and the notice in
 * `pages.contact.form_unavailable_*` comes out.
 *
 * @see BR-31, BR-36 · CONSTITUTION Art. 15, Art. 17, Art. 24
 * @see D-02 (mail, open) · D-14 (centre copy, open) · D-39 (this scope)
 */
final class PageController extends Controller
{
    public function about(): View
    {
        $sections = $this->sections('pages.about.sections');

        return view('public.about', [
            'screen' => 'about',
            'screenState' => ScreenState::of($sections === []),
            'sections' => $sections,
            'pageTitle' => __('pages.about.title'),
            'pageDescription' => __('pages.about.meta_description'),
        ]);
    }

    /**
     * Every channel on this page comes from configuration, never from the
     * template: one address, changed in one place, correct everywhere (BR-36).
     * A page that hardcoded the number would go stale the day it changes and
     * nothing would report it.
     */
    public function contact(): View
    {
        $whatsapp = (string) config('athar.whatsapp', '');
        $email = (string) config('athar.email', '');

        return view('public.contact', [
            'screen' => 'contact',
            // A contact page with no reachable channel is empty, not normal.
            'screenState' => ScreenState::of($whatsapp === '' && $email === ''),
            'whatsapp' => $whatsapp,
            'email' => $email,
            'pageTitle' => __('pages.contact.title'),
            'pageDescription' => __('pages.contact.meta_description'),
        ]);
    }

    /**
     * Read one structured block out of the language files.
     *
     * A key the translator has not written yet comes back from `__()` as the
     * key string itself, not as an array. That is why the array check is not
     * defensive noise: without it the page would print "pages.about.sections"
     * to a visitor.
     *
     * @return list<array<string, mixed>>
     */
    private function sections(string $key): array
    {
        $sections = __($key);

        if (! is_array($sections)) {
            return [];
        }

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
