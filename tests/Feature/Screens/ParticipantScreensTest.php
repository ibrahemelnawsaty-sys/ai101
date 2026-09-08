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

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
});

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
*/

it('شاشة الجدول: الحالة العادية تعرض الجلسات', function (): void {
    $start = riyadhAt('2026-10-13 18:00:00');
    sessionInCohort($this->cohort, $start, $start->addHours(3), ['title' => 'CANARY-SESSION-TITLE']);

    $body = $this->actingAs($this->participant)->get(route('schedule'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('CANARY-SESSION-TITLE');
});

it('شاشة الجدول: الحالة الفارغة حين لا جلسات', function (): void {
    $body = $this->actingAs($this->participant)->get(route('schedule'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('ورقة الطباعة تُعرض ولا تنهار — كانت خطأ 500 لا يغطّيه اختبار', function (): void {
    $start = riyadhAt('2026-10-13 18:00:00');
    sessionInCohort($this->cohort, $start, $start->addHours(3), ['title' => 'CANARY-PRINT-TITLE']);

    $body = $this->actingAs($this->participant)
        ->get(route('schedule.pdf'))
        ->assertOk()
        ->getContent();

    // The sheet has to name the session, and it has to say which timezone the
    // times are in — a printout leaves the context that made that obvious.
    expect($body)->toContain('CANARY-PRINT-TITLE')
        ->and($body)->toContain(__('schedule.timezone_note'))
        ->and($body)->not->toContain('schedule.print_title');
});

it('ورقة الطباعة بلا جلسات تعرض حالة فارغة لا صفحة بيضاء', function (): void {
    $body = $this->actingAs($this->participant)
        ->get(route('schedule.pdf'))
        ->assertOk()
        ->getContent();

    // A blank sheet reads as a printing fault; the empty state says otherwise
    // (CONSTITUTION art. 17).
    expect($body)->toContain(__('schedule.empty_title'));
});

it('شاشة الجدول: حالة التحميل هيكل بشكل المحتوى', function (): void {
    expect(renderSkeleton('schedule'))->toBeScreenState('loading');
});

/*
|--------------------------------------------------------------------------
| Attendance
|--------------------------------------------------------------------------
*/

it('شاشة الحضور: الحالة العادية تعرض سجل الحضور', function (): void {
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'present');

    $body = $this->actingAs($this->participant)->get(route('attendance.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal');
});

it('شاشة الحضور: الحالة الفارغة حين لا سجل بعد', function (): void {
    $body = $this->actingAs($this->participant)->get(route('attendance.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة الحضور: حالة التحميل هيكل بشكل المحتوى', function (): void {
    expect(renderSkeleton('attendance'))->toBeScreenState('loading');
});

/*
|--------------------------------------------------------------------------
| Assignments
|--------------------------------------------------------------------------
*/

it('شاشة المهام: الحالة العادية تعرض المهام المنشورة', function (): void {
    makeAssignment($this->cohort, ['title' => 'CANARY-ASSIGNMENT-TITLE', 'status' => 'published']);

    $body = $this->actingAs($this->participant)->get(route('assignments.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('CANARY-ASSIGNMENT-TITLE');
});

it('شاشة المهام: الحالة الفارغة حين لا مهام منشورة', function (): void {
    $body = $this->actingAs($this->participant)->get(route('assignments.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة المهام: حالة التحميل هيكل بشكل المحتوى', function (): void {
    expect(renderSkeleton('assignments'))->toBeScreenState('loading');
});

it('شاشة المهام: حالة الخطأ صفحة عربية مفهومة عند مهمة لا تخص المتدرب', function (): void {
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

it('شاشة الدرجات: الحالة العادية تعرض البنود المقيَّمة', function (): void {
    gradeAssignment($this->cohort, $this->participant, 10, 8);

    $body = $this->actingAs($this->participant)->get(route('grades'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal');
});

it('شاشة الدرجات: الحالة الفارغة قبل رصد أي درجة', function (): void {
    $body = $this->actingAs($this->participant)->get(route('grades'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة الدرجات: حالة التحميل هيكل بشكل المحتوى', function (): void {
    expect(renderSkeleton('grades'))->toBeScreenState('loading');
});

/*
|--------------------------------------------------------------------------
| Resources
|--------------------------------------------------------------------------
*/

it('شاشة الحقيبة: الحالة العادية تعرض الموارد', function (): void {
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

it('شاشة الحقيبة: الحالة الفارغة حين لا موارد', function (): void {
    $body = $this->actingAs($this->participant)->get(route('resources.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة الحقيبة: حالة التحميل هيكل بشكل المحتوى', function (): void {
    expect(renderSkeleton('resources'))->toBeScreenState('loading');
});

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

it('شاشة الإشعارات: الحالة العادية تعرض الإشعارات', function (): void {
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

it('شاشة الإشعارات: الحالة الفارغة حين لا إشعارات', function (): void {
    $body = $this->actingAs($this->participant)->get(route('notifications'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة الإشعارات: حالة التحميل هيكل بشكل المحتوى', function (): void {
    expect(renderSkeleton('notifications'))->toBeScreenState('loading');
});

/*
|--------------------------------------------------------------------------
| Messages, live sessions, journey, certificate
|--------------------------------------------------------------------------
*/

it('شاشة الرسائل: الحالة الفارغة حين لا محادثات', function (): void {
    $body = $this->actingAs($this->participant)->get(route('messages.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة الرسائل: الحالة العادية بعد وجود محادثة', function (): void {
    makeThreadFor($this->participant, $this->cohort, ['title' => 'CANARY-THREAD-TITLE']);

    $body = $this->actingAs($this->participant)->get(route('messages.index'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('CANARY-THREAD-TITLE');
});

it('شاشة المحاضرات المباشرة: الحالة الفارغة حين لا محاضرات قادمة', function (): void {
    $body = $this->actingAs($this->participant)->get(route('live'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة رحلتي: الحالة العادية تعرض الخطوات', function (): void {
    $weeks = collect(range(1, 4))->mapWithKeys(fn (int $i): array => [$i => makeWeek($this->cohort, $i)]);
    seedJourneySteps($this->cohort, $weeks->all());

    $body = $this->actingAs($this->participant)->get(route('participant.journey'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal');
});

it('شاشة الشهادة: الحالة الفارغة قبل صدور الشهادة', function (): void {
    $body = $this->actingAs($this->participant)->get(route('certificate'))->assertOk()->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة الشهادة: الحالة العادية بعد صدور الشهادة', function (): void {
    issueCertificateFor($this->participant, $this->cohort, ['serial_number' => 'ATHAR-AI101-2026-0042']);

    $body = $this->actingAs($this->participant)->get(route('certificate'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('ATHAR-AI101-2026-0042');
});

it('كل شاشات المتدرب تحمل وسم الشاشة واتجاه الصفحة من اليمين', function (): void {
    foreach (['dashboard', 'schedule', 'attendance.index', 'grades', 'resources.index', 'notifications'] as $name) {
        $body = $this->actingAs($this->participant)->get(route($name))->assertOk()->getContent();

        expect($body)->toContain('data-screen=')
            ->and($body)->toContain('dir="rtl"')
            ->and($body)->toContain('lang="ar"');
    }
});
