<?php

declare(strict_types=1);

/**
 * Article 17 — the four mandatory states of every participant screen.
 *
 * CONVENTION this suite establishes and then enforces, because the Constitution
 * requires the states but no document says how to recognise them from the outside:
 *  - every screen root carries data-screen="<name>" and data-state="normal|empty|error";
 *  - the loading state is a Blade partial at resources/views/partials/skeletons/<name>.blade.php
 *    whose root carries data-state="loading";
 *  - the empty state carries copy specific to that one screen (asserted collectively
 *    in FourStatesContractTest).
 *
 * @see CONSTITUTION.md Article 17 · PRD §9.5 · PROJECT-CONTRACT.md §12
 */

use App\Models\Notification;
use App\Models\Resource;

beforeEach(function () {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
});

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
*/

it('شاشة الجدول: الحالة العادية تعرض الجلسات', function () {
    $start = riyadhAt('2026-10-13 18:00:00');
    sessionInCohort($this->cohort, $start, $start->addHours(3), ['title' => 'CANARY-SESSION-TITLE']);

    $body = $this->actingAs($this->participant)->get(route('schedule'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('CANARY-SESSION-TITLE');
});

it('شاشة الجدول: الحالة الفارغة حين لا جلسات', function () {
    $body = $this->actingAs($this->participant)->get(route('schedule'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة الجدول: حالة التحميل هيكل بشكل المحتوى', function () {
    expect(renderSkeleton('schedule'))->toBeScreenState('loading');
});

/*
|--------------------------------------------------------------------------
| Attendance
|--------------------------------------------------------------------------
*/

it('شاشة الحضور: الحالة العادية تعرض سجل الحضور', function () {
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'present');

    $body = $this->actingAs($this->participant)->get(route('attendance.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal');
});

it('شاشة الحضور: الحالة الفارغة حين لا سجل بعد', function () {
    $body = $this->actingAs($this->participant)->get(route('attendance.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة الحضور: حالة التحميل هيكل بشكل المحتوى', function () {
    expect(renderSkeleton('attendance'))->toBeScreenState('loading');
});

/*
|--------------------------------------------------------------------------
| Assignments
|--------------------------------------------------------------------------
*/

it('شاشة المهام: الحالة العادية تعرض المهام المنشورة', function () {
    makeAssignment($this->cohort, ['title' => 'CANARY-ASSIGNMENT-TITLE', 'status' => 'published']);

    $body = $this->actingAs($this->participant)->get(route('assignments.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('CANARY-ASSIGNMENT-TITLE');
});

it('شاشة المهام: الحالة الفارغة حين لا مهام منشورة', function () {
    $body = $this->actingAs($this->participant)->get(route('assignments.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة المهام: حالة التحميل هيكل بشكل المحتوى', function () {
    expect(renderSkeleton('assignments'))->toBeScreenState('loading');
});

it('شاشة المهام: حالة الخطأ صفحة عربية مفهومة عند مهمة لا تخص المتدرب', function () {
    $foreign = makeAssignment(makeCohort(), ['title' => 'CANARY-FOREIGN-ASSIGNMENT']);

    $response = $this->actingAs($this->participant)->get(route('assignments.show', $foreign));

    $response->assertForbidden();

    expect($response->getContent())->not->toContain('CANARY-FOREIGN-ASSIGNMENT')
        ->and($response->getContent())->not->toContain('Exception')
        ->and($response->getContent())->not->toContain('vendor/laravel');
});

/*
|--------------------------------------------------------------------------
| Grades
|--------------------------------------------------------------------------
*/

it('شاشة الدرجات: الحالة العادية تعرض البنود المقيَّمة', function () {
    gradeAssignment($this->cohort, $this->participant, 10, 8);

    $body = $this->actingAs($this->participant)->get(route('grades'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal');
});

it('شاشة الدرجات: الحالة الفارغة قبل رصد أي درجة', function () {
    $body = $this->actingAs($this->participant)->get(route('grades'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة الدرجات: حالة التحميل هيكل بشكل المحتوى', function () {
    expect(renderSkeleton('grades'))->toBeScreenState('loading');
});

/*
|--------------------------------------------------------------------------
| Resources
|--------------------------------------------------------------------------
*/

it('شاشة الحقيبة: الحالة العادية تعرض الموارد', function () {
    Resource::factory()->create([
        'cohort_id' => $this->cohort->id,
        'type' => 'file',
        'title' => 'CANARY-RESOURCE-TITLE',
        'download_count' => 0,
    ]);

    $body = $this->actingAs($this->participant)->get(route('resources.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('CANARY-RESOURCE-TITLE');
});

it('شاشة الحقيبة: الحالة الفارغة حين لا موارد', function () {
    $body = $this->actingAs($this->participant)->get(route('resources.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة الحقيبة: حالة التحميل هيكل بشكل المحتوى', function () {
    expect(renderSkeleton('resources'))->toBeScreenState('loading');
});

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

it('شاشة الإشعارات: الحالة العادية تعرض الإشعارات', function () {
    Notification::factory()->create([
        'user_id' => $this->participant->id,
        'title' => 'CANARY-NOTIFICATION-TITLE',
        'is_read' => false,
        'read_at' => null,
    ]);

    $body = $this->actingAs($this->participant)->get(route('notifications'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('CANARY-NOTIFICATION-TITLE');
});

it('شاشة الإشعارات: الحالة الفارغة حين لا إشعارات', function () {
    $body = $this->actingAs($this->participant)->get(route('notifications'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة الإشعارات: حالة التحميل هيكل بشكل المحتوى', function () {
    expect(renderSkeleton('notifications'))->toBeScreenState('loading');
});

/*
|--------------------------------------------------------------------------
| Messages, live sessions, journey, certificate
|--------------------------------------------------------------------------
*/

it('شاشة الرسائل: الحالة الفارغة حين لا محادثات', function () {
    $body = $this->actingAs($this->participant)->get(route('messages.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة الرسائل: الحالة العادية بعد وجود محادثة', function () {
    makeThreadFor($this->participant, $this->cohort, ['title' => 'CANARY-THREAD-TITLE']);

    $body = $this->actingAs($this->participant)->get(route('messages.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('CANARY-THREAD-TITLE');
});

it('شاشة المحاضرات المباشرة: الحالة الفارغة حين لا محاضرات قادمة', function () {
    $body = $this->actingAs($this->participant)->get(route('live'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة رحلتي: الحالة العادية تعرض الخطوات', function () {
    $weeks = collect(range(1, 4))->mapWithKeys(fn (int $i): array => [$i => makeWeek($this->cohort, $i)]);
    seedJourneySteps($this->cohort, $weeks->all());

    $body = $this->actingAs($this->participant)->get(route('participant.journey'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal');
});

it('شاشة الشهادة: الحالة الفارغة قبل صدور الشهادة', function () {
    $body = $this->actingAs($this->participant)->get(route('certificate'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة الشهادة: الحالة العادية بعد صدور الشهادة', function () {
    issueCertificateFor($this->participant, $this->cohort, ['serial_number' => 'ATHAR-AI101-2026-0042']);

    $body = $this->actingAs($this->participant)->get(route('certificate'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('ATHAR-AI101-2026-0042');
});

it('كل شاشات المتدرب تحمل وسم الشاشة واتجاه الصفحة من اليمين', function () {
    foreach (['dashboard', 'schedule', 'attendance.index', 'grades', 'resources.index', 'notifications'] as $name) {
        $body = $this->actingAs($this->participant)->get(route($name))->assertOk()->getContent();

        expect($body)->toContain('data-screen=')
            ->and($body)->toContain('dir="rtl"')
            ->and($body)->toContain('lang="ar"');
    }
});
