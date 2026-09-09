<?php

declare(strict_types=1);

/**
 * `robots.txt` and `sitemap.xml` exist, and neither invites a crawler inside.
 *
 * WHY THIS SUITE EXISTS
 * PRD §9.1.3 requires both files. Both answered 404 in production: the landing
 * page carried a full Schema.org graph and a canonical link while telling
 * crawlers nothing about what to index — and, more seriously, nothing about what
 * to leave alone.
 *
 * The second half is the one that matters. `/verify/{token}` and
 * `/certificate/verify/{code}` are public by design so an employer can check a
 * certificate, but they carry a token in the path, and BR-25 does not intend
 * them to accumulate in a search index. A missing robots.txt is not a neutral
 * absence: it is a standing invitation to index every one of them.
 *
 * @see BR-25, BR-36 · PRD §9.1.3
 */

use App\Models\Cohort;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));
});

it('PRD §9.1.3: robots.txt يُقدَّم بنوع محتوى نصّي', function (): void {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
});

it('BR-25: robots.txt يمنع فهرسة صفحات التحقق واللوحات', function (): void {
    $body = $this->get('/robots.txt')->assertOk()->getContent();

    // The token-bearing pages first: these are the reason the file exists.
    expect($body)->toContain('Disallow: /verify')
        ->and($body)->toContain('Disallow: /certificate/verify')
        ->and($body)->toContain('Disallow: /admin')
        ->and($body)->toContain('Disallow: /trainer')
        ->and($body)->toContain('Disallow: /participant')
        // And it points at the sitemap, which is how a crawler finds it at all.
        ->and($body)->toContain('Sitemap: '.url('/sitemap.xml'));
});

it('PRD §9.1.3: sitemap.xml يُقدَّم كـ XML صالح ويسرد الصفحات العامة', function (): void {
    $response = $this->get('/sitemap.xml')->assertOk();

    $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $body = $response->getContent();

    // Parses, rather than merely looks like XML.
    expect(simplexml_load_string($body))->not->toBeFalse();

    expect($body)->toContain('<loc>'.route('home').'</loc>')
        ->and($body)->toContain('<loc>'.route('programs').'</loc>')
        ->and($body)->toContain('<loc>'.route('terms').'</loc>')
        ->and($body)->toContain('<loc>'.route('privacy').'</loc>');
});

it('BR-25: خريطة الموقع لا تسرد صفحة تحقق ولا مسارًا محميًّا', function (): void {
    $body = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($body)->not->toContain('/verify')
        ->and($body)->not->toContain('/admin')
        ->and($body)->not->toContain('/participant')
        ->and($body)->not->toContain('/login');
});

it('BR-36: خريطة الموقع تؤرَّخ بآخر تغيير في المحتوى لا بتاريخ اليوم', function (): void {
    // A sitemap that claims every page changed this morning teaches a crawler to
    // ignore the field, so lastmod tracks the content instead of the clock.
    $cohort = makeCohort();

    $cohort->forceFill(['updated_at' => riyadhAt('2026-09-14 09:00:00')])->save();

    Cohort::query()->getConnection()->table('programs')
        ->where('id', $cohort->program_id)
        ->update(['updated_at' => riyadhAt('2026-09-12 09:00:00')]);

    $body = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($body)->toContain('<lastmod>2026-09-14</lastmod>')
        ->and($body)->not->toContain('<lastmod>2026-09-20</lastmod>');
});
