<?php

declare(strict_types=1);

/**
 * Phase 2 (2-C) — the filters a participant's screens DRAW must be filters the
 * server APPLIES.
 *
 * Notifications, the training kit, past recordings, the attendance log and the
 * timetable each printed a filter bar and read none of it: the choice went into
 * the query string and the same rows came back.
 *
 * The rules every case below holds to:
 *   · a filter only NARROWS — it starts from the participant's own cohort and
 *     their own rows, so no value in the query string widens what they see
 *     (BR-22, BR-23);
 *   · a value the screen never offered is ignored, not an error, and not "no
 *     results";
 *   · a filter changes what is LISTED, never what is COUNTED — the unread badge,
 *     the attendance summary and each week's attended tally keep their meaning
 *     (BR-01..BR-06 are not touched; nothing here computes attendance).
 *
 * @see BR-22, BR-23 · FR-NOTIF-02, FR-RES-05, FR-LIVE-08, FR-ATT-26, FR-SCHED-13 · D-136
 */

use App\Models\Notification;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->w1 = makeWeek($this->cohort, 1, ['title' => 'Week alpha']);
    $this->w2 = makeWeek($this->cohort, 2, ['title' => 'Week beta']);
    $this->me = makeParticipant($this->cohort);
    $this->other = makeParticipant($this->cohort);
});

/** What a paginator or collection of presenters lists, by one published key. */
function listed(mixed $rows, string $key): array
{
    $items = $rows instanceof Illuminate\Contracts\Pagination\Paginator ? $rows->items() : $rows;

    return collect($items)->map(static fn ($row) => $row[$key])->sort()->values()->all();
}

/*
|--------------------------------------------------------------------------
| Notifications — FR-NOTIF-02
|--------------------------------------------------------------------------
*/

function notice(User $user, string $type, string $title, bool $read = false): Notification
{
    return Notification::factory()->create([
        'user_id' => $user->id,
        'type' => $type,
        'title' => $title,
        'is_read' => $read,
        'read_at' => $read ? riyadhAt('2026-10-12 11:00:00') : null,
    ]);
}

function noticeCase(object $test): void
{
    notice($test->me, 'assignment_published', 'A-unread');
    notice($test->me, 'grade_recorded', 'B-read', true);
    notice($test->me, 'assignment_published', 'C-read', true);
    notice($test->other, 'assignment_published', 'Z-someone-else');
}

it('FR-NOTIF-02: مرشّح حالة القراءة «غير مقروء» يعرض غير المقروء وحده', function (): void {
    noticeCase($this);

    $page = $this->actingAs($this->me)->get(route('notifications', ['state' => 'unread']))->assertOk();

    expect(listed($page->viewData('notifications'), 'title'))->toBe(['A-unread']);
});

it('FR-NOTIF-02: مرشّح «مقروء» يعرض المقروء وحده', function (): void {
    noticeCase($this);

    $page = $this->actingAs($this->me)->get(route('notifications', ['state' => 'read']))->assertOk();

    expect(listed($page->viewData('notifications'), 'title'))->toBe(['B-read', 'C-read']);
});

it('FR-NOTIF-02: مرشّح النوع يعرض هذا النوع وحده، ومعه الحالة يعملان معًا', function (): void {
    noticeCase($this);

    $byType = $this->actingAs($this->me)
        ->get(route('notifications', ['type' => 'assignment_published']))->assertOk();
    expect(listed($byType->viewData('notifications'), 'title'))->toBe(['A-unread', 'C-read']);

    $both = $this->actingAs($this->me)
        ->get(route('notifications', ['type' => 'assignment_published', 'state' => 'read']))->assertOk();
    expect(listed($both->viewData('notifications'), 'title'))->toBe(['C-read']);
});

it('FR-NOTIF-02: قيمة لم تعرضها الشاشة تُتجاهل فتعود القائمة كاملة لا فارغة', function (): void {
    noticeCase($this);

    $page = $this->actingAs($this->me)
        ->get(route('notifications', ['type' => 'not-a-type', 'state' => 'sideways']))->assertOk();

    expect(listed($page->viewData('notifications'), 'title'))->toBe(['A-unread', 'B-read', 'C-read']);
});

it('BR-22: المرشّح لا يوسّع النطاق — إشعار غيري لا يظهر بأي قيمة، وعدّاد غير المقروء يبقى للكل', function (): void {
    noticeCase($this);

    foreach ([[], ['state' => 'unread'], ['type' => 'assignment_published'], ['state' => 'read']] as $query) {
        $page = $this->actingAs($this->me)->get(route('notifications', $query))->assertOk();

        expect(listed($page->viewData('notifications'), 'title'))->not->toContain('Z-someone-else');
        expect($page->viewData('unreadCount'))->toBe(1);
    }
})->group('authz');

/*
|--------------------------------------------------------------------------
| Training kit — FR-RES-05
|--------------------------------------------------------------------------
*/

function kitCase(object $test): void
{
    Resource::factory()->create([
        'cohort_id' => $test->cohort->id, 'week_id' => $test->w1->id, 'type' => 'file',
        'title' => 'Alpha guide', 'description' => 'covers prompt basics',
    ]);
    Resource::factory()->create([
        'cohort_id' => $test->cohort->id, 'week_id' => $test->w2->id, 'type' => 'link',
        'title' => 'Beta link', 'description' => 'agents reading list',
        'file_url' => null, 'external_url' => 'https://example.com/agents',
    ]);
    Resource::factory()->create([
        'cohort_id' => $test->cohort->id, 'week_id' => null, 'type' => 'video',
        'title' => 'Gamma video', 'description' => 'a walkthrough',
        'file_url' => null, 'external_url' => 'https://example.com/walk',
    ]);

    $foreign = makeCohort();
    Resource::factory()->create(['cohort_id' => $foreign->id, 'title' => 'Zed foreign', 'type' => 'file']);
}

function kitTitles(TestResponse $page): array
{
    return collect($page->viewData('groups'))
        ->flatMap(static fn ($group) => $group['items'])
        ->map(static fn ($item) => $item['title'])
        ->sort()->values()->all();
}

it('FR-RES-05: البحث النصي يطابق العنوان والوصف', function (): void {
    kitCase($this);

    $byTitle = $this->actingAs($this->me)->get(route('resources.index', ['q' => 'Beta']))->assertOk();
    expect(kitTitles($byTitle))->toBe(['Beta link']);

    $byDescription = $this->actingAs($this->me)->get(route('resources.index', ['q' => 'prompt basics']))->assertOk();
    expect(kitTitles($byDescription))->toBe(['Alpha guide']);
});

it('FR-RES-05: مرشّح النوع ومرشّح الأسبوع يعملان منفردين ومجتمعين', function (): void {
    kitCase($this);

    expect(kitTitles($this->actingAs($this->me)->get(route('resources.index', ['type' => 'link']))))
        ->toBe(['Beta link']);

    expect(kitTitles($this->actingAs($this->me)->get(route('resources.index', ['week' => $this->w1->id]))))
        ->toBe(['Alpha guide']);

    expect(kitTitles($this->actingAs($this->me)->get(route('resources.index', ['type' => 'file', 'week' => $this->w2->id]))))
        ->toBe([]);
});

it('FR-RES-05: قيمة لم تعرضها الشاشة تُتجاهل، وأسبوع دفعة أخرى يُعامل كذلك', function (): void {
    kitCase($this);
    $foreignWeek = makeWeek(makeCohort(), 1);

    expect(kitTitles($this->actingAs($this->me)->get(route('resources.index', ['type' => 'nonsense']))))
        ->toBe(['Alpha guide', 'Beta link', 'Gamma video']);

    // Another cohort's week is "a value the screen never offered": ignored, so
    // the participant sees their own cohort's kit — never the foreign one's.
    expect(kitTitles($this->actingAs($this->me)->get(route('resources.index', ['week' => $foreignWeek->id]))))
        ->toBe(['Alpha guide', 'Beta link', 'Gamma video']);
});

it('BR-23: مواد دفعة أخرى لا تظهر بأي مرشّح', function (): void {
    kitCase($this);

    foreach ([[], ['q' => 'Zed'], ['type' => 'file']] as $query) {
        expect(kitTitles($this->actingAs($this->me)->get(route('resources.index', $query))))
            ->not->toContain('Zed foreign');
    }
})->group('authz');

it('FR-RES-05: مع مرشّح فعّال لا تُرسم أقسام الأسابيع الفارغة بعبارة «لا مواد بعد»', function (): void {
    kitCase($this);

    $page = $this->actingAs($this->me)->get(route('resources.index', ['week' => $this->w1->id]))->assertOk();

    expect(collect($page->viewData('groups')))->toHaveCount(1);
    $page->assertDontSee(__('resources.group_empty_title'));

    // Unfiltered, an empty week is still shown as empty (art. 17): the two
    // states are different sentences.
    $all = $this->actingAs($this->me)->get(route('resources.index'))->assertOk();
    expect(collect($all->viewData('groups')))->toHaveCount(3);
});

/*
|--------------------------------------------------------------------------
| Past recordings — FR-LIVE-08
|--------------------------------------------------------------------------
*/

function recordingCase(object $test): void
{
    foreach ([
        ['Prompt basics', $test->w1, 1],
        ['Agents in practice', $test->w2, 2],
    ] as [$topic, $week, $offset]) {
        $start = riyadhAt('2026-10-05 18:00:00')->addDays($offset);
        sessionInCohort($test->cohort, $start, $start->addHours(3), [
            'topic' => $topic,
            'week_id' => $week->id,
            'status' => 'completed',
            'recording_url' => 'https://zoom.us/rec/share/'.$offset,
        ]);
    }
}

it('FR-LIVE-08: اختيار الأسبوع الثاني يعرض تسجيلاته فقط', function (): void {
    recordingCase($this);

    $page = $this->actingAs($this->me)->get(route('live', ['week' => $this->w2->id]))->assertOk();

    expect(listed($page->viewData('recordings'), 'topic'))->toBe(['Agents in practice']);
});

it('FR-LIVE-08: البحث النصي في التسجيلات، ومع الأسبوع يعملان معًا', function (): void {
    recordingCase($this);

    $page = $this->actingAs($this->me)->get(route('live', ['q' => 'Prompt']))->assertOk();
    expect(listed($page->viewData('recordings'), 'topic'))->toBe(['Prompt basics']);

    $none = $this->actingAs($this->me)->get(route('live', ['q' => 'Prompt', 'week' => $this->w2->id]))->assertOk();
    expect(listed($none->viewData('recordings'), 'topic'))->toBe([]);
});

it('FR-LIVE-08: أسبوع لم تعرضه الشاشة يُتجاهل فتبقى القائمة كاملة', function (): void {
    recordingCase($this);

    $page = $this->actingAs($this->me)->get(route('live', ['week' => 'not-a-week']))->assertOk();

    expect(listed($page->viewData('recordings'), 'topic'))->toBe(['Agents in practice', 'Prompt basics']);
});

/*
|--------------------------------------------------------------------------
| Attendance log — FR-ATT-26
|--------------------------------------------------------------------------
*/

function logCase(object $test): array
{
    $present = sessionAttendedBy($test->cohort, $test->me, 'training', 'present', 0, $test->w1->id);
    $late = sessionAttendedBy($test->cohort, $test->me, 'training', 'late', 1, $test->w2->id);
    $absent = sessionAttendedBy($test->cohort, $test->me, 'training', 'absent', 2, $test->w2->id);
    // Somebody else's row in one of the same sessions — never mine, whatever the filter.
    makeAttendance($present, $test->other, 'late');

    $rowOf = static fn ($session): string => (string) App\Models\Attendance::query()
        ->where('session_id', $session->id)->where('user_id', $test->me->id)->value('id');

    return [$rowOf($present), $rowOf($late), $rowOf($absent)];
}

it('FR-ATT-26: مرشّح الحالة يعرض هذه الحالة وحدها من سجلّي', function (): void {
    [$present, $late, $absent] = logCase($this);

    $page = $this->actingAs($this->me)->get(route('attendance.index', ['status' => 'late']))->assertOk();
    expect(listed($page->viewData('records'), 'id'))->toBe([$late]);
});

it('FR-ATT-26: مرشّح الأسبوع، ومع الحالة يعملان معًا', function (): void {
    [$present, $late, $absent] = logCase($this);

    $byWeek = $this->actingAs($this->me)->get(route('attendance.index', ['week' => $this->w2->id]))->assertOk();
    expect(listed($byWeek->viewData('records'), 'id'))->toBe(collect([$late, $absent])->sort()->values()->all());

    $both = $this->actingAs($this->me)
        ->get(route('attendance.index', ['week' => $this->w2->id, 'status' => 'absent']))->assertOk();
    expect(listed($both->viewData('records'), 'id'))->toBe([$absent]);
});

it('FR-ATT-26: قيمة لم تعرضها الشاشة تُتجاهل', function (): void {
    [$present, $late, $absent] = logCase($this);

    $page = $this->actingAs($this->me)
        ->get(route('attendance.index', ['status' => 'sideways', 'week' => 'not-a-week']))->assertOk();

    expect(listed($page->viewData('records'), 'id'))->toBe(collect([$present, $late, $absent])->sort()->values()->all());
});

it('BR-22: المرشّح لا يوسّع النطاق — صفّ غيري لا يظهر بأي قيمة', function (): void {
    logCase($this);
    $othersRow = (string) App\Models\Attendance::query()->where('user_id', $this->other->id)->value('id');

    foreach ([[], ['status' => 'late'], ['week' => $this->w1->id], ['status' => 'present']] as $query) {
        $page = $this->actingAs($this->me)->get(route('attendance.index', $query))->assertOk();

        expect(listed($page->viewData('records'), 'id'))->not->toContain($othersRow);
    }
})->group('authz');

it('BR-01: المرشّح يغيّر ما يُعرض في السجل لا ما يُحسب — ملخّص الحضور واحد بأي مرشّح', function (): void {
    logCase($this);

    $all = $this->actingAs($this->me)->get(route('attendance.index'))->assertOk();
    $summary = $all->viewData('summary')->toArray();

    foreach ([['status' => 'late'], ['week' => $this->w2->id], ['status' => 'absent', 'week' => $this->w2->id]] as $query) {
        $filtered = $this->actingAs($this->me)->get(route('attendance.index', $query))->assertOk();

        expect($filtered->viewData('summary')->toArray())->toEqual($summary);
    }
});

/*
|--------------------------------------------------------------------------
| Timetable — FR-SCHED-13
|--------------------------------------------------------------------------
*/

function timetableCase(object $test): array
{
    $trainingPresent = sessionAttendedBy($test->cohort, $test->me, 'training', 'present', 0, $test->w1->id);
    $projectLate = sessionAttendedBy($test->cohort, $test->me, 'project', 'late', 1, $test->w1->id);
    $introNoRow = sessionAttendedBy($test->cohort, $test->me, 'intro', null, 7, $test->w2->id);
    // Another participant's row on the same session must not colour MY filter.
    makeAttendance($introNoRow, $test->other, 'present');

    return [$trainingPresent->id, $projectLate->id, $introNoRow->id];
}

function timetableIds(TestResponse $page): array
{
    return collect($page->viewData('weeks'))
        ->flatMap(static fn ($week) => $week['sessions'])
        ->map(static fn ($session) => (string) $session['id'])
        ->sort()->values()->all();
}

it('FR-SCHED-13: مرشّح نوع الجلسة يعرض هذا النوع وحده', function (): void {
    [$training, $project, $intro] = timetableCase($this);

    $page = $this->actingAs($this->me)->get(route('schedule', ['type' => 'project']))->assertOk();

    expect(timetableIds($page))->toBe([(string) $project]);
});

it('FR-SCHED-13: مرشّح حالة حضوري يطابق سجلّي أنا وحده، ومع النوع يعملان معًا', function (): void {
    [$training, $project, $intro] = timetableCase($this);

    $late = $this->actingAs($this->me)->get(route('schedule', ['attendance' => 'late']))->assertOk();
    expect(timetableIds($late))->toBe([(string) $project]);

    $present = $this->actingAs($this->me)->get(route('schedule', ['attendance' => 'present']))->assertOk();
    expect(timetableIds($present))->toBe([(string) $training]);

    $both = $this->actingAs($this->me)
        ->get(route('schedule', ['attendance' => 'late', 'type' => 'training']))->assertOk();
    expect(timetableIds($both))->toBe([]);
});

it('FR-SCHED-13: مرشّح الأسبوع يحصر القائمة في هذا الأسبوع', function (): void {
    [$training, $project, $intro] = timetableCase($this);

    $page = $this->actingAs($this->me)->get(route('schedule', ['week' => $this->w2->id]))->assertOk();

    expect(timetableIds($page))->toBe([(string) $intro]);
});

it('FR-SCHED-13: قيمة لم تعرضها الشاشة تُتجاهل فيعرض الجدول كاملًا', function (): void {
    [$training, $project, $intro] = timetableCase($this);

    $page = $this->actingAs($this->me)
        ->get(route('schedule', ['type' => 'nonsense', 'attendance' => 'sideways', 'week' => 'not-a-week']))
        ->assertOk();

    expect(timetableIds($page))->toBe(collect([$training, $project, $intro])->map(fn ($id) => (string) $id)->sort()->values()->all());
});

it('BR-01: مرشّح الجدول يغيّر ما يُعرض لا ما يُعدّ — حصيلة الأسبوع (حضرتُ كذا من كذا) واحدة بأي مرشّح', function (): void {
    timetableCase($this);

    $tally = static fn (TestResponse $page): array => collect($page->viewData('weeks'))
        ->mapWithKeys(static fn ($week) => [$week['title'] => [$week['sessionCount'], $week['attendedCount']]])
        ->all();

    $all = $tally($this->actingAs($this->me)->get(route('schedule'))->assertOk());

    foreach ([['type' => 'project'], ['attendance' => 'late'], ['attendance' => 'present', 'type' => 'training']] as $query) {
        $filtered = $this->actingAs($this->me)->get(route('schedule', $query))->assertOk();

        // Weeks the filter emptied may drop out of the list; the ones that
        // remain carry the numbers of the whole week.
        foreach ($tally($filtered) as $title => $numbers) {
            expect($numbers)->toBe($all[$title]);
        }
    }
});

/*
|--------------------------------------------------------------------------
| The empty state under a filter says what happened (art. 17)
|--------------------------------------------------------------------------
*/

it('FR-ATT-26: مرشّح لا يطابق شيئًا يقول «لا سجلّ يطابق التصفية» لا «لم يبدأ سجلّك»، ومعه رابط إزالة التصفية', function (): void {
    sessionAttendedBy($this->cohort, $this->me, 'training', 'present', 0, $this->w1->id);

    $page = $this->actingAs($this->me)->get(route('attendance.index', ['status' => 'absent']))->assertOk();

    $page->assertSee(__('attendance.log.no_match_title'))
        ->assertDontSee(__('attendance.log.empty_title'))
        ->assertSee(route('attendance.index'), escape: false);

    // Without a filter, an empty log keeps its own first-time sentence.
    $fresh = $this->actingAs($this->other)->get(route('attendance.index'))->assertOk();
    $fresh->assertSee(__('attendance.log.empty_title'))->assertDontSee(__('attendance.log.no_match_title'));
});

it('FR-LIVE-08: بحث لا يطابق تسجيلًا يقول «لا تسجيلات تطابق بحثك» لا «لا تسجيلات بعد»', function (): void {
    recordingCase($this);

    $page = $this->actingAs($this->me)->get(route('live', ['q' => 'zzz-nothing']))->assertOk();

    $page->assertSee(__('live.recordings_no_match_title'))->assertDontSee(__('live.recordings_empty_title'));
});

it('FR-SCHED-13: أسبوع أفرغته التصفية لا يُقال عنه «لا جلسات في هذا الأسبوع»', function (): void {
    timetableCase($this);

    $page = $this->actingAs($this->me)->get(route('schedule', ['type' => 'closing']))->assertOk();

    // The two titles share their opening words, so the sentences that differ are
    // the explanations.
    $page->assertSee(__('schedule.week_no_match_body'))->assertDontSee(__('schedule.week_empty_body'));
});

/*
|--------------------------------------------------------------------------
| The independent review (Art. 27), each finding pinned
|--------------------------------------------------------------------------
*/

it('FR-SCHED-13: تنقّل التقويم بالأسابيع لا يحصر قائمة الأسابيع — لكل منهما مفتاحه', function (): void {
    [$training, $project, $intro] = timetableCase($this);

    // Two real, different weeks: today (12 Oct) is in the first.
    $this->w1->update(['start_date' => '2026-10-11', 'end_date' => '2026-10-17']);
    $this->w2->update(['start_date' => '2026-10-18', 'end_date' => '2026-10-24']);

    $range = fn (array $query) => $this->actingAs($this->me)->get(route('schedule', $query))->assertOk();

    $default = $range([])->viewData('calendar')['rangeLabel'];

    // The calendar's own key moves the calendar…
    $moved = $range(['calendar_week' => $this->w2->id, 'view' => 'calendar']);
    expect($moved->viewData('calendar')['rangeLabel'])->not->toBe($default);

    // …and leaves the accordion listing every week. The arrows used `week`, the
    // accordion's FILTER key: going to week 2 in the calendar left the list
    // showing week 2 only when the person came back to it.
    expect(timetableIds($moved))->toBe(collect([$training, $project, $intro])->map(static fn ($id) => (string) $id)->sort()->values()->all());

    // The accordion filter, for its part, does not move the calendar.
    expect($range(['week' => $this->w2->id])->viewData('calendar')['rangeLabel'])->toBe($default);
});

it('FR-SCHED-13: أسهم التقويم تحمل المرشّحات الجارية — لا تُفقد عند الانتقال بين الأسابيع', function (): void {
    timetableCase($this);

    $html = (string) $this->actingAs($this->me)
        ->get(route('schedule', ['type' => 'project', 'view' => 'calendar']))->assertOk()->getContent();

    expect($html)->toContain('type=project')->toContain('calendar_week=');
});

it('FR-SCHED-13: يوم في التقويم أفرغته التصفية لا يُقال عنه «لا جلسات في هذا اليوم»', function (): void {
    timetableCase($this);

    $filtered = $this->actingAs($this->me)
        ->get(route('schedule', ['type' => 'project', 'view' => 'calendar']))->assertOk();

    $filtered->assertSee(__('schedule.day_no_match'))
        ->assertDontSee(__('schedule.no_sessions_that_day'));

    // Unfiltered, an empty day IS empty.
    $this->actingAs($this->me)->get(route('schedule', ['view' => 'calendar']))->assertOk()
        ->assertSee(__('schedule.no_sessions_that_day'))
        ->assertDontSee(__('schedule.day_no_match'));
});
