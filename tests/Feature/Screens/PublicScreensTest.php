<?php

declare(strict_types=1);

/**
 * Article 17 for the pages a stranger can reach: the landing page, the two public
 * verification pages, and the legal pages.
 *
 * These carry the highest disclosure risk in the platform, so each test asserts both
 * the state marker and the absence of anything the page has no business revealing.
 *
 * @see BR-25, BR-31, BR-36 · PRD §9.1, §9.6, §9.17 · CONSTITUTION.md Article 17
 */

use App\Enums\CohortStatus;
use App\Models\DigitalCard;
use App\Models\LandingSetting;
use App\Models\Profile;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));

    $this->cohort = makeCohort(['capacity' => 30]);
    $this->participant = makeParticipant($this->cohort);

    $this->profile = Profile::factory()->create([
        'user_id' => $this->participant->id,
        'phone' => '0501234567',
        'city' => 'CANARY-CITY',
    ]);
});

it('صفحة الهبوط: الحالة العادية بمحتوى من قاعدة البيانات', function (): void {
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'hero_text' => 'CANARY-HERO-TEXT',
        'is_registration_open' => true,
    ]);

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('CANARY-HERO-TEXT')
        ->and($body)->toContain('dir="rtl"');
});

it('صفحة الهبوط: حالة التحميل هيكل بشكل المحتوى', function (): void {
    expect(renderSkeleton('landing'))->toBeScreenState('loading');
});

it('BR-31: شريط المؤشرات وشارات البطل والشريط المتحرك تُشتق من الدفعة لا من نص ثابت', function (): void {
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'hero_text' => 'CANARY-HERO-TEXT',
        'is_registration_open' => true,
    ]);

    makeWeek($this->cohort, 1, ['title' => 'CANARY-WEEK-ONE']);
    makeWeek($this->cohort, 2, ['title' => 'CANARY-WEEK-TWO']);

    $start = riyadhAt('2026-09-22 18:00:00');
    sessionInCohort($this->cohort, $start, $start->addHours(2));

    $body = $this->get(route('home'))->assertOk()->getContent();

    // The three bands used to return [] from the controller, so each rendered
    // as nothing at all and the page had visible holes where its texture was.
    expect($body)->toContain('trust__grid')
        ->and($body)->toContain('hero__chips')
        ->and($body)->toContain('tick__i')
        // The marquee carries the real week titles, not invented copy.
        ->and($body)->toContain('CANARY-WEEK-ONE')
        ->and($body)->toContain('CANARY-WEEK-TWO')
        // The threshold shown is the cohort's own, so moving it in the admin
        // panel moves it here and nowhere else (BR-31, BR-36).
        ->and($body)->toContain('>75</span>%')
        // The marker-pen sweep: its CSS and its IntersectionObserver were both
        // complete, but no template ever carried the class, so it had never
        // once rendered. Wrapped whole, never per letter (Article 16-bis).
        ->and($body)->toContain('class="hl"')
        ->and($body)->toContain('class="hl hl--v"');
});

it('صفحة الهبوط لا تعرض معرّفات المتطلبات ولا أسماء مفاتيح الإعداد للزائر', function (): void {
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'is_registration_open' => true,
    ]);

    $body = $this->get(route('home'))->assertOk()->getContent();

    // These four were rendered as visible badges in the certificate simulator:
    // developer shorthand no visitor can read, on the centre's shop window.
    foreach (['>BR-11<', '>BR-26<', 'pass_score', 'min_attendance'] as $leak) {
        expect($body)->not->toContain($leak);
    }
});

it('شريط المؤشرات يختفي كليًّا بلا دفعة بدل أن يطبع حالته الفارغة للزائر', function (): void {
    // `completed` is outside the landing page's preference list, so no cohort
    // is featured and the whole band has nothing to stand on.
    $this->cohort->status = CohortStatus::Completed;
    $this->cohort->save();

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->not->toContain('trust__grid')
        ->and($body)->not->toContain(__('landing.states.empty_trust'));
});

it('صفحة الهبوط: التسجيل مغلق يظهر بحالته الخاصة لا بحالة فارغة عامة', function (): void {
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'hero_text' => 'CANARY-HERO-TEXT',
        'is_registration_open' => false,
    ]);

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain('data-registration="closed"');
});

it('صفحة الهبوط: لا تكشف أي بيانات شخصية لأي مسجل', function (): void {
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'is_registration_open' => true,
    ]);

    $body = $this->get(route('home'))->assertOk()->getContent();

    foreach ([$this->participant->email, $this->profile->phone, 'CANARY-CITY', $this->participant->id] as $secret) {
        expect($body)->not->toContain((string) $secret);
    }
});

it('صفحة التحقق من البطاقة: الحالة العادية ببطاقة سارية', function (): void {
    $card = DigitalCard::factory()->create([
        'user_id' => $this->participant->id,
        'cohort_id' => $this->cohort->id,
        'issued_at' => riyadhAt('2026-09-01 09:00:00'),
        'revoked_at' => null,
    ]);

    $body = $this->get(route('card.verify', $card->qr_token))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal');
});

it('صفحة التحقق من البطاقة: حالة الخطأ برمز غير صالح لا تكشف شيئًا', function (): void {
    $response = $this->get(route('card.verify', 'not-a-real-token'));

    expect($response->status())->toBeIn([200, 404]);

    $body = $response->getContent();

    expect($body)->not->toContain($this->participant->email)
        ->and($body)->not->toContain('Exception')
        ->and($body)->not->toContain('vendor/laravel');
});

it('صفحة التحقق من الشهادة: الحالة العادية بشهادة سارية', function (): void {
    $certificate = issueCertificateFor($this->participant, $this->cohort);

    $body = $this->get(route('certificate.verify', $certificate->verify_code))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal');
});

it('صفحة التحقق من الشهادة: حالة الخطأ برمز غير موجود', function (): void {
    $response = $this->get(route('certificate.verify', 'no-such-verify-code'));

    expect($response->status())->toBeIn([200, 404])
        ->and($response->getContent())->not->toContain('Exception');
});

it('الصفحات النظامية متاحة وتحمل الاتجاه واللغة الصحيحين', function (): void {
    foreach (['terms', 'privacy'] as $name) {
        $body = $this->get(route($name))->assertOk()->getContent();

        expect($body)->toContain('dir="rtl"')
            ->and($body)->toContain('lang="ar"');
    }
});

it('صفحة الدخول تُعرض للزائر وتحوّل المسجل إلى لوحته', function (): void {
    $this->get(route('login'))->assertOk();

    $this->actingAs($this->participant)->get(route('login'))->assertRedirect(route('dashboard'));
});

it('صفحات الخطأ العامة عربية ولا تكشف أثر التنفيذ', function (): void {
    foreach (['403', '404', '500'] as $code) {
        expect(view()->exists('errors.'.$code))
            ->toBeTrue("Missing Arabic error screen for HTTP {$code}");

        $rendered = view('errors.'.$code, ['exception' => new RuntimeException('CANARY-INTERNAL-DETAIL')])->render();

        expect($rendered)->not->toContain('CANARY-INTERNAL-DETAIL')
            ->and($rendered)->not->toContain('vendor/laravel')
            ->and($rendered)->toContain('dir="rtl"');
    }
});
