<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\CohortStatus;
use App\Http\Controllers\Controller;
use App\Models\Cohort;
use App\Models\Program;
use App\Services\Time\Clock;
use Illuminate\Http\Response;

/**
 * `robots.txt` and `sitemap.xml`.
 *
 * Both are required by PRD §9.1.3 and both answered 404 in production, so the
 * landing page was asking to be indexed while telling crawlers nothing about
 * what to index or what to leave alone.
 *
 * They are routes rather than files under `public/`, for two reasons. The deploy
 * rsyncs only `build/`, `fonts/` and `brand/` into the web root, so a file added
 * beside them would never arrive. And both documents name the site's own URLs,
 * which come from APP_URL — writing the host into a static file would put a
 * second copy of the domain in the repository, which BR-36 does not allow.
 *
 * Every URL listed here is public and unauthenticated. The disallow list is the
 * other half of that promise: the panels, the participant area and the two
 * verification pages, which carry a token or a serial in the path and must never
 * turn up in a search result (BR-25).
 *
 * @see BR-25, BR-36 · PRD §9.1.3 · CONSTITUTION.md Article 12
 */
final class CrawlerController extends Controller
{
    /** Paths that must never be indexed, by prefix. */
    private const DISALLOWED = [
        '/admin',
        '/trainer',
        '/participant',
        '/login',
        '/register',
        '/forgot-password',
        '/reset-password',
        // BR-25: these carry a card token and a certificate serial. They are
        // public by design and indexable by nobody.
        '/verify',
        '/certificate/verify',
    ];

    public function robots(): Response
    {
        $lines = ['User-agent: *'];

        foreach (self::DISALLOWED as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.url('/sitemap.xml');
        $lines[] = '';

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $updated = $this->lastChange();

        $urls = [
            ['loc' => route('home'), 'priority' => '1.0', 'freq' => 'daily'],
            ['loc' => route('programs'), 'priority' => '0.8', 'freq' => 'weekly'],
            ['loc' => route('about'), 'priority' => '0.5', 'freq' => 'monthly'],
            ['loc' => route('contact'), 'priority' => '0.5', 'freq' => 'monthly'],
            ['loc' => route('terms'), 'priority' => '0.3', 'freq' => 'yearly'],
            ['loc' => route('privacy'), 'priority' => '0.3', 'freq' => 'yearly'],
        ];

        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $body .= '  <url>'."\n"
                .'    <loc>'.e($url['loc']).'</loc>'."\n"
                .'    <lastmod>'.$updated.'</lastmod>'."\n"
                .'    <changefreq>'.$url['freq'].'</changefreq>'."\n"
                .'    <priority>'.$url['priority'].'</priority>'."\n"
                .'  </url>'."\n";
        }

        $body .= '</urlset>'."\n";

        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * The date the public content last moved, as `YYYY-MM-DD`.
     *
     * A crawler re-reads a page sooner when `lastmod` moves, so it tracks the
     * newest published programme or cohort rather than today's date — a sitemap
     * that claims every page changed this morning teaches the crawler to ignore
     * the field. Falls back to the server clock when nothing is published yet.
     */
    private function lastChange(): string
    {
        $cohort = Cohort::query()
            ->whereIn('status', array_map(
                static fn (CohortStatus $status): string => $status->value,
                Cohort::FEATURED_PREFERENCE,
            ))
            ->orderByDesc('updated_at')
            ->first();

        $program = Program::query()
            ->where('status', 'published')
            ->orderByDesc('updated_at')
            ->first();

        $newest = null;

        foreach ([$cohort?->getAttribute('updated_at'), $program?->getAttribute('updated_at')] as $stamp) {
            if (! $stamp instanceof \DateTimeInterface) {
                continue;
            }

            $riyadh = Clock::toRiyadh($stamp);

            if ($newest === null || $riyadh->greaterThan($newest)) {
                $newest = $riyadh;
            }
        }

        return ($newest ?? Clock::riyadh())->format('Y-m-d');
    }
}
