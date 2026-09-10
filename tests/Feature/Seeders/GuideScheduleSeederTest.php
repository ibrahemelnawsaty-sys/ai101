<?php

declare(strict_types=1);

/**
 * The guide seeder is safe to run on a live database, and says so by proof.
 *
 * WHY THIS SUITE EXISTS
 * This seeder is meant to be run against PRODUCTION, which no other seeder in
 * this project can survive: they all use model factories, and factories need
 * Faker, which `--no-dev` deletes. A seeder aimed at live data has to earn it,
 * so the three properties that make it safe are asserted rather than asserted-to:
 *
 *   idempotent      — a second run changes nothing and duplicates nothing
 *   non-destructive — no attendance, evaluation or enrolment row is touched
 *   faithful        — the dates and titles are the guide's, not derived ones
 *
 * The second is the one that matters most. A seeder that re-ran and wiped a
 * month of attendance would be the worst defect in this repository, and the only
 * way to know it does not is to put attendance in front of it and look after.
 *
 * @see BR-07, BR-31 · PRD §9.1.1, §9.10 · D-61, D-62
 */

use App\Models\Attendance;
use App\Models\Cohort;
use App\Models\FinalProject;
use App\Models\Session;
use Database\Seeders\GuideScheduleSeeder;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-10 12:00:00'));

    // A published programme is the seeder's only precondition.
    $this->cohort = makeCohort(['name' => 'الدفعة الأولى']);
});

it('PRD §9.1.1: البذرة تُنشئ ثلاث عشرة جلسة بتواريخ الدليل', function (): void {
    $this->seed(GuideScheduleSeeder::class);

    $cohort = Cohort::query()->where('name', 'الدفعة الأولى')->firstOrFail();

    expect($cohort->sessions()->count())->toBe(13)
        ->and($cohort->sessions()->where('type', 'intro')->count())->toBe(1)
        ->and($cohort->sessions()->where('type', 'training')->count())->toBe(11)
        ->and($cohort->sessions()->where('type', 'closing')->count())->toBe(1);

    // The three dates the public timeline needs, from the guide's own agenda.
    expect($cohort->sessions()->where('type', 'intro')->value('date')->format('Y-m-d'))->toBe('2026-09-05')
        ->and($cohort->sessions()->where('type', 'closing')->value('date')->format('Y-m-d'))->toBe('2026-10-01');
});

it('D-61: جلسة واحدة يوميًّا من 16:00 إلى 20:00 — حضور واحد لا اثنان', function (): void {
    $this->seed(GuideScheduleSeeder::class);

    $cohort = Cohort::query()->where('name', 'الدفعة الأولى')->firstOrFail();

    $sessions = $cohort->sessions()->orderBy('date')->get();

    // One row per calendar day: no day carries two sessions.
    expect($sessions->pluck('date')->map->format('Y-m-d')->duplicates())->toBeEmpty();

    foreach ($sessions as $session) {
        expect((string) $session->getAttribute('start_time'))->toStartWith('16:00')
            ->and((string) $session->getAttribute('end_time'))->toStartWith('20:00');
    }
});

it('المادة 29: تشغيلها مرّتين لا يُنشئ نسخة واحدة مكرّرة', function (): void {
    $this->seed(GuideScheduleSeeder::class);

    $cohort = Cohort::query()->where('name', 'الدفعة الأولى')->firstOrFail();

    $before = [
        'cohorts' => Cohort::query()->count(),
        'sessions' => $cohort->sessions()->count(),
        'weeks' => $cohort->weeks()->count(),
        'projects' => FinalProject::query()->count(),
    ];

    $this->seed(GuideScheduleSeeder::class);
    $this->seed(GuideScheduleSeeder::class);

    expect([
        'cohorts' => Cohort::query()->count(),
        'sessions' => $cohort->sessions()->count(),
        'weeks' => $cohort->weeks()->count(),
        'projects' => FinalProject::query()->count(),
    ])->toBe($before);
});

it('المادة 8: إعادة التشغيل لا تمسّ حضورًا مسجّلًا', function (): void {
    // This is the property that makes the seeder runnable on production at all.
    $this->seed(GuideScheduleSeeder::class);

    $cohort = Cohort::query()->where('name', 'الدفعة الأولى')->firstOrFail();
    $participant = makeParticipant($cohort);

    /** @var Session $session */
    $session = $cohort->sessions()->where('type', 'training')->orderBy('date')->firstOrFail();

    $attendance = makeAttendance($session, $participant, 'present');

    $this->seed(GuideScheduleSeeder::class);

    // Same row, same id, same status — not replaced, not deleted.
    expect(Attendance::query()->count())->toBe(1)
        ->and(Attendance::query()->value('id'))->toBe($attendance->getKey())
        ->and(Session::query()->whereKey($session->getKey())->exists())->toBeTrue();
});

it('المادة 17: إعادة التشغيل لا تُلغي إلغاءً سجّله المدرّب', function (): void {
    $this->seed(GuideScheduleSeeder::class);

    $cohort = Cohort::query()->where('name', 'الدفعة الأولى')->firstOrFail();

    /** @var Session $session */
    $session = $cohort->sessions()->where('type', 'training')->orderBy('date')->firstOrFail();
    $session->forceFill(['status' => 'cancelled'])->save();

    $this->seed(GuideScheduleSeeder::class);

    expect($session->fresh()?->getAttribute('status')->value ?? $session->fresh()?->getAttribute('status'))
        ->toBe('cancelled');
});

it('BR-15: البذرة لا تفتح المشروع الختامي — فتحه فعل المدرّب', function (): void {
    $this->seed(GuideScheduleSeeder::class);

    $project = FinalProject::query()->firstOrFail();

    expect($project->getAttribute('is_unlocked'))->toBeFalse()
        ->and($project->getAttribute('unlocked_at'))->toBeNull()
        ->and($project->getAttribute('due_at')->format('Y-m-d'))->toBe('2026-10-01');
});

it('BR-31: البذرة لا تقرّر حالة التسجيل ولا السعة', function (): void {
    $cohort = Cohort::query()->where('name', 'الدفعة الأولى')->firstOrFail();

    $cohort->forceFill(['status' => 'open', 'capacity' => 77])->save();

    $this->seed(GuideScheduleSeeder::class);

    // Both belong to the centre. A seeder that wrote them would be deciding
    // whether the programme is on sale.
    expect($cohort->fresh()?->getAttribute('capacity'))->toBe(77)
        ->and($cohort->fresh()?->getAttribute('status')->value)->toBe('open');
});

it('PRD §9.1.1: الأسابيع الأربعة بتواريخ الدليل ومحاوره', function (): void {
    $this->seed(GuideScheduleSeeder::class);

    $cohort = Cohort::query()->where('name', 'الدفعة الأولى')->firstOrFail();

    $weeks = $cohort->weeks()->orderBy('index')->get();

    expect($weeks)->toHaveCount(4);

    expect($weeks[0]->getAttribute('start_date')->format('Y-m-d'))->toBe('2026-09-07')
        ->and($weeks[3]->getAttribute('end_date')->format('Y-m-d'))->toBe('2026-09-30')
        ->and($weeks[0]->getAttribute('title'))->toContain('Python')
        ->and($weeks[1]->getAttribute('title'))->toContain('الرؤية بالحاسب')
        ->and($weeks[2]->getAttribute('title'))->toContain('معالجة اللغات الطبيعية')
        ->and($weeks[3]->getAttribute('title'))->toContain('التوليدي والتوكيلي');
});

it('PRD §9.1.1: الخط الزمني العام يعرض خمس مراحل بعد البذر', function (): void {
    // The whole point of the seeder: the public section that showed two
    // milestones now shows every stage the guide names.
    $this->seed(GuideScheduleSeeder::class);

    Cohort::query()->where('name', 'الدفعة الأولى')
        ->update(['status' => 'running']);

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect(preg_match('/<ol class="tl__row">(.*?)<\/ol>/s', $body, $row))->toBe(1);

    foreach (['registration_closes', 'intro_session', 'training_weeks', 'final_project', 'closing_session'] as $stage) {
        expect($row[1])->toContain(__('landing.headings.timeline.'.$stage));
    }
});
