<?php

declare(strict_types=1);

/**
 * The trainer's roster refreshes itself while a session is live (PRD §9.9.7).
 *
 * WHY THIS SUITE EXISTS
 * The endpoint returned raw status values and its client had been removed, so
 * the roster never refreshed; and it asked a WRITE ability for a read (D-72).
 * The client now patches cells the server renders, named by `data-cell` — a
 * contract between the page and the endpoint that the first case compares,
 * because the class of bug this project keeps meeting is two files agreeing on
 * a name nothing checks.
 *
 * @see BR-07, BR-23 · PRD §9.9.7 · D-72
 */

use App\Models\Attendance;

beforeEach(function (): void {
    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
    $this->participant = makeParticipant($this->cohort);

    $this->start = riyadhAt('2026-10-12 18:00:00');
    $this->end = riyadhAt('2026-10-12 21:00:00');
    $this->session = sessionInCohort($this->cohort, $this->start, $this->end);

    freezeAt($this->start->addMinutes(10));
});

it('D-72: كل خانة يسمّيها صفّ الكشف تعيدها نقطة التحديث، ولا خانة زائدة', function (): void {
    $html = (string) $this->actingAs($this->trainer)
        ->get(route('trainer.attendance', ['session' => $this->session->id]))
        ->assertOk()
        ->assertSee('data-cell="present"', false)
        ->assertSee('atharRoster(', false)
        ->getContent();

    $id = (string) $this->participant->id;
    expect(preg_match('#<tr data-participant="'.preg_quote($id, '#').'">(.*?)</tr>#s', $html, $row))->toBe(1);
    preg_match_all('/data-cell="([a-z]+)"/', $row[1], $cells);

    $rows = $this->actingAs($this->trainer)
        ->getJson(route('trainer.attendance.poll', $this->session))
        ->assertOk()
        ->json('rows');

    $pageCells = $cells[1];
    $apiCells = array_keys($rows[$id] ?? []);
    sort($pageCells);
    sort($apiCells);

    expect($apiCells)->toBe($pageCells)->and($pageCells)->not->toBe([]);
});

it('D-72: تسجيل الحضور يظهر في التحديث التالي — الوقت والحالة والعدّاد وزرّ التعديل', function (): void {
    $id = (string) $this->participant->id;

    $before = $this->actingAs($this->trainer)->getJson(route('trainer.attendance.poll', $this->session))->json();

    Attendance::factory()->create([
        'session_id' => $this->session->id,
        'user_id' => $this->participant->id,
        'status' => 'present',
        'check_in_at' => $this->start->addMinutes(5),
    ]);

    $after = $this->actingAs($this->trainer)->getJson(route('trainer.attendance.poll', $this->session))->json();

    expect($before['present'])->toBe(0)
        ->and($before['rows'][$id]['in'])->toBe('—')
        ->and($before['rows'][$id]['edit'])->toContain(e((string) __('trainer.attendance.edit_needs_record')))
        ->and($after['present'])->toBe(1)
        ->and($after['rows'][$id]['in'])->not->toBe('—')
        ->and($after['rows'][$id]['status'])->toContain(e(App\Enums\AttendanceStatus::Present->label()))
        ->and($after['rows'][$id]['edit'])->toContain('edit='.$id)
        ->and($after['isLive'])->toBeTrue();
});

it('D-72: بعد انتهاء الجلسة يقول التحديث إنها لم تعد حيّة', function (): void {
    freezeAt($this->end->addSecond());

    expect($this->actingAs($this->trainer)->getJson(route('trainer.attendance.poll', $this->session))->json('isLive'))
        ->toBeFalse();
});

it('BR-23: مدرّب دفعة أخرى لا يقرأ كشف هذه الجلسة', function (): void {
    $this->actingAs(makeTrainer(makeCohort()))
        ->getJson(route('trainer.attendance.poll', $this->session))
        ->assertForbidden();
});

it('BR-23: المتدرّب لا يقرأ كشف الجلسة', function (): void {
    $this->actingAs($this->participant)
        ->getJson(route('trainer.attendance.poll', $this->session))
        ->assertForbidden();
});
