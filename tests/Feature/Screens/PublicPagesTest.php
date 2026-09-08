<?php

declare(strict_types=1);

/**
 * Article 17 for the three standing public pages added by `D-39`:
 * about the centre, contact channels, and the programme directory.
 *
 * The directory carries the real risk of the three. It is a public listing
 * driven by a database query, which is the exact shape that leaks a draft when
 * the scope is forgotten — so it is tested from the outside, by publishing one
 * programme and hiding two, rather than by asserting the scope exists.
 *
 * @see BR-31, BR-36 · CONSTITUTION.md Article 5, Article 15, Article 17 · D-39
 */

use App\Models\Cohort;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));
});

/*
|--------------------------------------------------------------------------
| About
|--------------------------------------------------------------------------
*/

it('D-39: صفحة عن المركز تُعرض بحالة عادية ونصّها من ملفات اللغة', function (): void {
    $body = $this->get(route('about'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('dir="rtl"')
        ->and($body)->toContain((string) __('pages.about.title'))
        ->and($body)->toContain((string) __('pages.about.lead'))
        // A missing translation renders as the key itself; that must never
        // reach a visitor.
        ->and($body)->not->toContain('pages.about.');
});

it('D-39: صفحة عن المركز لا تطبع HTML خامًّا من ملفات اللغة [المادة 24]', function (): void {
    app('translator')->addLines([
        'pages.about.sections' => [
            ['heading' => '<script>x</script>', 'paragraphs' => ['<b>CANARY</b>'], 'items' => []],
        ],
    ], 'ar');

    $body = $this->get(route('about'))->assertOk()->getContent();

    expect($body)->not->toContain('<script>x</script>')
        ->and($body)->not->toContain('<b>CANARY</b>')
        ->and($body)->toContain('CANARY');
});

/*
|--------------------------------------------------------------------------
| Contact
|--------------------------------------------------------------------------
*/

it('BR-36: صفحة تواصل معنا تقرأ القنوات من الإعدادات لا من القالب', function (): void {
    config([
        'athar.whatsapp' => '966500000001',
        'athar.email' => 'canary-contact@example.test',
    ]);

    $body = $this->get(route('contact'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('966500000001')
        ->and($body)->toContain('canary-contact@example.test')
        ->and($body)->toContain('https://wa.me/966500000001');
});

it('D-02: صفحة تواصل معنا لا تعرض نموذج مراسلة ما دام البريد معطّلًا', function (): void {
    $body = $this->get(route('contact'))->assertOk()->getContent();

    // No form means no POST target and no CSRF field on the page at all: a
    // visitor cannot be given the impression a message was sent.
    expect($body)->not->toContain('<form')
        ->and($body)->toContain((string) __('pages.contact.form_unavailable_title'));
});

it('D-39: صفحة تواصل معنا تعرض حالتها الفارغة حين لا قناة مضبوطة إطلاقًا', function (): void {
    config(['athar.whatsapp' => '', 'athar.email' => '']);

    $body = $this->get(route('contact'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

/*
|--------------------------------------------------------------------------
| Programme directory
|--------------------------------------------------------------------------
*/

it('D-39: دليل البرامج يعرض حالته الفارغة حين لا برنامج منشور', function (): void {
    makeProgram(['status' => 'draft', 'name_ar' => 'CANARY-DRAFT']);

    $body = $this->get(route('programs'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty')
        ->and($body)->toContain((string) __('pages.programs.empty_title'))
        ->and($body)->not->toContain('CANARY-DRAFT');
});

it('المادة 5: دليل البرامج لا يسرّب مسودّة ولا برنامجًا مؤرشفًا', function (): void {
    makeProgram(['status' => 'published', 'name_ar' => 'CANARY-PUBLISHED']);
    makeProgram(['status' => 'draft', 'name_ar' => 'CANARY-DRAFT']);
    makeProgram(['status' => 'archived', 'name_ar' => 'CANARY-ARCHIVED']);

    $body = $this->get(route('programs'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('CANARY-PUBLISHED')
        ->and($body)->not->toContain('CANARY-DRAFT')
        ->and($body)->not->toContain('CANARY-ARCHIVED');
});

it('D-39: عدّاد الدفعات يحسب المفتوحة والقادمة فقط', function (): void {
    $program = makeProgram(['status' => 'published', 'name_ar' => 'CANARY-COUNT']);

    Cohort::factory()->create([
        'program_id' => $program->id,
        'status' => 'open',
        'pass_score' => 60,
        'min_attendance_rate' => 75,
        'capacity' => 30,
    ]);

    // A finished cohort must not inflate the figure a visitor reads.
    Cohort::factory()->create([
        'program_id' => $program->id,
        'status' => 'completed',
        'pass_score' => 60,
        'min_attendance_rate' => 75,
        'capacity' => 30,
    ]);

    $body = $this->get(route('programs'))->assertOk()->getContent();

    expect($body)->toContain(
        (string) trans_choice('pages.programs.cohorts_choice', 1, ['count' => 1]),
    );
});

it('المادة 15: أرقام دليل البرامج لاتينية', function (): void {
    $program = makeProgram(['status' => 'published']);

    Cohort::factory()->count(3)->create([
        'program_id' => $program->id,
        'status' => 'open',
        'pass_score' => 60,
        'min_attendance_rate' => 75,
        'capacity' => 30,
    ]);

    expect($this->get(route('programs'))->assertOk()->getContent())->toUseLatinNumerals();
});

/*
|--------------------------------------------------------------------------
| Shared contract
|--------------------------------------------------------------------------
*/

it('المادة 17: الصفحات الثلاث تحمل وسم الشاشة وحالتها', function (): void {
    foreach (['about' => 'about', 'contact' => 'contact', 'programs' => 'programs'] as $route => $screen) {
        $body = $this->get(route($route))->assertOk()->getContent();

        expect($body)->toContain('data-screen="'.$screen.'"')
            ->and($body)->toContain('data-state=');
    }
});

it('D-39: الصفحات الثلاث موصولة من تذييل الموقع العام', function (): void {
    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain(route('about'))
        ->and($body)->toContain(route('contact'))
        ->and($body)->toContain(route('programs'));
});
