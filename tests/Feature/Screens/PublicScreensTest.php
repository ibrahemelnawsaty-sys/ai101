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

use App\Models\DigitalCard;
use App\Models\LandingSetting;
use App\Models\Profile;

beforeEach(function () {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));

    $this->cohort = makeCohort(['capacity' => 30]);
    $this->participant = makeParticipant($this->cohort);

    $this->profile = Profile::factory()->create([
        'user_id' => $this->participant->id,
        'phone' => '0501234567',
        'city' => 'CANARY-CITY',
    ]);
});

it('صفحة الهبوط: الحالة العادية بمحتوى من قاعدة البيانات', function () {
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

it('صفحة الهبوط: حالة التحميل هيكل بشكل المحتوى', function () {
    expect(renderSkeleton('landing'))->toBeScreenState('loading');
});

it('صفحة الهبوط: التسجيل مغلق يظهر بحالته الخاصة لا بحالة فارغة عامة', function () {
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'hero_text' => 'CANARY-HERO-TEXT',
        'is_registration_open' => false,
    ]);

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain('data-registration="closed"');
});

it('صفحة الهبوط: لا تكشف أي بيانات شخصية لأي مسجل', function () {
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'is_registration_open' => true,
    ]);

    $body = $this->get(route('home'))->assertOk()->getContent();

    foreach ([$this->participant->email, $this->profile->phone, 'CANARY-CITY', $this->participant->id] as $secret) {
        expect($body)->not->toContain((string) $secret);
    }
});

it('صفحة التحقق من البطاقة: الحالة العادية ببطاقة سارية', function () {
    $card = DigitalCard::factory()->create([
        'user_id' => $this->participant->id,
        'cohort_id' => $this->cohort->id,
        'issued_at' => riyadhAt('2026-09-01 09:00:00'),
        'revoked_at' => null,
    ]);

    $body = $this->get(route('card.verify', $card->qr_token))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal');
});

it('صفحة التحقق من البطاقة: حالة الخطأ برمز غير صالح لا تكشف شيئًا', function () {
    $response = $this->get(route('card.verify', 'not-a-real-token'));

    expect($response->status())->toBeIn([200, 404]);

    $body = $response->getContent();

    expect($body)->not->toContain($this->participant->email)
        ->and($body)->not->toContain('Exception')
        ->and($body)->not->toContain('vendor/laravel');
});

it('صفحة التحقق من الشهادة: الحالة العادية بشهادة سارية', function () {
    $certificate = issueCertificateFor($this->participant, $this->cohort);

    $body = $this->get(route('certificate.verify', $certificate->verify_code))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal');
});

it('صفحة التحقق من الشهادة: حالة الخطأ برمز غير موجود', function () {
    $response = $this->get(route('certificate.verify', 'no-such-verify-code'));

    expect($response->status())->toBeIn([200, 404])
        ->and($response->getContent())->not->toContain('Exception');
});

it('الصفحات النظامية متاحة وتحمل الاتجاه واللغة الصحيحين', function () {
    foreach (['terms', 'privacy'] as $name) {
        $body = $this->get(route($name))->assertOk()->getContent();

        expect($body)->toContain('dir="rtl"')
            ->and($body)->toContain('lang="ar"');
    }
});

it('صفحة الدخول تُعرض للزائر وتحوّل المسجل إلى لوحته', function () {
    $this->get(route('login'))->assertOk();

    $this->actingAs($this->participant)->get(route('login'))->assertRedirect(route('dashboard'));
});

it('صفحات الخطأ العامة عربية ولا تكشف أثر التنفيذ', function () {
    foreach (['403', '404', '500'] as $code) {
        expect(view()->exists('errors.'.$code))
            ->toBeTrue("Missing Arabic error screen for HTTP {$code}");

        $rendered = view('errors.'.$code, ['exception' => new RuntimeException('CANARY-INTERNAL-DETAIL')])->render();

        expect($rendered)->not->toContain('CANARY-INTERNAL-DETAIL')
            ->and($rendered)->not->toContain('vendor/laravel')
            ->and($rendered)->toContain('dir="rtl"');
    }
});
