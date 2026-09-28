<?php

declare(strict_types=1);

/**
 * The D-127 migration on a database as the live platform has it: a project
 * already OPEN to its trainees before "available" existed. It must come out
 * available AND published — with the same moment and the same person — so no
 * trainee loses the project mid-hand-in, and a locked project must stay locked
 * and unavailable. `migrate:fresh` never exercises this: an empty database has
 * nothing to carry across.
 *
 * @see D-127 · BR-15 · CONSTITUTION Article 29
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function availabilityMigration(): object
{
    return require database_path('migrations/2026_09_28_100000_add_availability_to_final_projects.php');
}

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-28 12:00:00'));

    availabilityMigration()->down();
});

it('D-127: الترحيل يعدّ المشروع المفتوح متاحًا ومنشورًا بالوقت والشخص نفسيهما، ويبقي المقفل مقفلًا', function (): void {
    expect(Schema::hasColumn('final_projects', 'is_available'))->toBeFalse();

    $admin = makeAdmin();
    $open = makeCohort();
    $locked = makeCohort();

    $row = static fn (string $cohortId, bool $unlocked) => [
        'id' => (string) Illuminate\Support\Str::uuid(),
        'cohort_id' => $cohortId,
        'title' => 'Final project',
        'brief' => 'Brief',
        'is_unlocked' => $unlocked,
        'unlocked_at' => $unlocked ? riyadhAt('2026-09-24 19:00:00') : null,
        'unlocked_by' => $unlocked ? $admin->id : null,
        'due_at' => riyadhAt('2026-09-30 23:59:00'),
        'max_score' => 50,
        'attachments' => '[]',
        'created_at' => riyadhAt('2026-09-20 10:00:00'),
        'updated_at' => riyadhAt('2026-09-20 10:00:00'),
    ];

    DB::table('final_projects')->insert([$row($open->id, true), $row($locked->id, false)]);

    availabilityMigration()->up();
    availabilityMigration()->up(); // safe to run again

    $openRow = DB::table('final_projects')->where('cohort_id', $open->id)->sole();
    $lockedRow = DB::table('final_projects')->where('cohort_id', $locked->id)->sole();

    expect((bool) $openRow->is_available)->toBeTrue()
        ->and((bool) $openRow->is_unlocked)->toBeTrue()
        ->and($openRow->available_by)->toBe($admin->id)
        ->and((string) $openRow->available_at)->toBe((string) $openRow->unlocked_at)
        ->and((bool) $lockedRow->is_available)->toBeFalse()
        ->and($lockedRow->available_at)->toBeNull();

    availabilityMigration()->down();

    expect(Schema::hasColumn('final_projects', 'is_available'))->toBeFalse()
        ->and(Schema::hasColumn('final_projects', 'available_by'))->toBeFalse();

    availabilityMigration()->up();
});
