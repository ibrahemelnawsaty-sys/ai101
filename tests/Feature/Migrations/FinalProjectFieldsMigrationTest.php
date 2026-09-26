<?php

declare(strict_types=1);

/**
 * The D-121 migration on a database as the live platform has it: an open
 * project with no fields, and hand-ins written by D-110's fixed form (and one
 * from before it). Production reaches the new form only through this path, and
 * `migrate:fresh` never exercises it — there is nothing to carry across in an
 * empty database — so it is run here against rows written the old way.
 *
 * @see BR-19 · FR-PROJ-10 · D-110, D-121 · CONSTITUTION Article 29
 */

use App\Models\FinalProject;
use App\Models\FinalProjectField;
use App\Models\ProjectSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function fieldsMigration(): object
{
    return require database_path('migrations/2026_09_26_100000_create_final_project_fields_table.php');
}

/** A hand-in row exactly as D-110's controller wrote it. */
function legacyHandIn(string $projectId, string $userId, array $columns): string
{
    $id = (string) Str::uuid7();

    DB::table('project_submissions')->insert($columns + [
        'id' => $id,
        'final_project_id' => $projectId,
        'user_id' => $userId,
        'files' => null,
        'live_url' => null,
        'github_url' => null,
        'presentation_file' => null,
        'logo_file' => null,
        'description' => null,
        'submitted_at' => riyadhAt('2026-09-25 20:00:00'),
        'is_late' => false,
        'version' => 1,
        'status' => 'submitted',
        'created_at' => riyadhAt('2026-09-25 20:00:00'),
        'updated_at' => riyadhAt('2026-09-25 20:00:00'),
    ]);

    return $id;
}

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-26 15:00:00'));

    fieldsMigration()->down();

    $this->cohort = makeCohort();
    $this->project = FinalProject::factory()->create(['cohort_id' => $this->cohort->id, 'is_unlocked' => true]);
});

it('D-121: الترحيل يعطي مشروع الدفعة القائم الحقول الافتراضية الخمسة ويحوّل تسليمه السابق بلا فقد', function (): void {
    $deck = ['disk' => 'private', 'path' => 'final-projects/x/deck.pdf', 'original_name' => 'deck.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 2048, 'checksum' => 'abc'];
    $logo = ['disk' => 'private', 'path' => 'final-projects/x/logo.png', 'original_name' => 'logo.png', 'mime_type' => 'image/png', 'size_bytes' => 512, 'checksum' => 'def'];

    $handIn = legacyHandIn($this->project->id, makeParticipant($this->cohort)->id, [
        'live_url' => 'https://example.test/live',
        'github_url' => 'https://github.com/trainee/final',
        'presentation_file' => json_encode($deck),
        'logo_file' => json_encode($logo),
        'description' => 'CANARY-DESCRIPTION',
    ]);

    fieldsMigration()->up();

    $fields = FinalProjectField::query()->where('final_project_id', $this->project->id)->ordered()->get();

    expect($fields->pluck('label')->all())->toBe([
        __('project.default_fields.live_url.label'),
        __('project.default_fields.github_url.label'),
        __('project.default_fields.presentation_file.label'),
        __('project.default_fields.logo_file.label'),
        __('project.default_fields.description.label'),
    ])
        ->and($fields[0]->tips)->toBe(__('project.default_fields.live_url.tips'))
        ->and($fields[2]->accepted_formats)->toBe(['pdf', 'powerpoint'])
        ->and($fields[2]->max_kilobytes)->toBe(25600)
        ->and($fields[3]->max_kilobytes)->toBe(4096)
        ->and($fields[3]->is_required)->toBeFalse();

    $answers = ProjectSubmission::query()->findOrFail($handIn)->answers;

    expect($answers)->toHaveCount(5)
        ->and(array_column($answers, 'field_id'))->toBe($fields->pluck('id')->all())
        ->and($answers[0]['value'])->toBe('https://example.test/live')
        ->and($answers[1]['value'])->toBe('https://github.com/trainee/final')
        ->and($answers[2]['files'])->toBe([$deck])
        ->and($answers[3]['files'])->toBe([$logo])
        ->and($answers[4]['value'])->toBe('CANARY-DESCRIPTION')
        ->and($answers[4]['type'])->toBe('textarea');

    // The columns it was read from are exactly as they were.
    $row = DB::table('project_submissions')->where('id', $handIn)->first();

    expect($row->live_url)->toBe('https://example.test/live')
        ->and(json_decode((string) $row->presentation_file, true))->toBe($deck)
        ->and($row->description)->toBe('CANARY-DESCRIPTION');
});

it('D-121: تسليم أقدم من D-110 بقائمة ملفات يُحفظ كله — العناصر الفارغة فارغة والملفات بعنصر خاص', function (): void {
    $older = [
        ['disk' => 'private', 'path' => 'final-projects/y/a.pdf', 'original_name' => 'a.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10],
        ['disk' => 'private', 'path' => 'final-projects/y/b.zip', 'original_name' => 'b.zip', 'mime_type' => 'application/zip', 'size_bytes' => 20],
    ];

    $handIn = legacyHandIn($this->project->id, makeParticipant($this->cohort)->id, [
        'files' => json_encode($older),
        'github_url' => 'https://github.com/trainee/old',
    ]);

    fieldsMigration()->up();

    $answers = ProjectSubmission::query()->findOrFail($handIn)->answers;

    expect($answers)->toHaveCount(6)
        ->and($answers[0]['value'])->toBeNull()
        ->and($answers[1]['value'])->toBe('https://github.com/trainee/old')
        ->and($answers[2]['files'])->toBe([])
        ->and($answers[5]['field_id'])->toBeNull()
        ->and($answers[5]['label'])->toBe(__('project.legacy_files_label'))
        ->and($answers[5]['files'])->toBe($older);
});

it('D-121: كل مشروع قائم يأخذ حقوله وحده، وكل تسليم يُربط بحقول مشروعه لا مشروع غيره', function (): void {
    $other = FinalProject::factory()->create(['cohort_id' => makeCohort()->id]);
    $mine = legacyHandIn($this->project->id, makeParticipant($this->cohort)->id, ['live_url' => 'https://example.test/mine']);
    $theirs = legacyHandIn($other->id, makeUser('participant')->id, ['live_url' => 'https://example.test/theirs']);

    fieldsMigration()->up();

    $mineFieldIds = FinalProjectField::query()->where('final_project_id', $this->project->id)->pluck('id')->all();
    $theirFieldIds = FinalProjectField::query()->where('final_project_id', $other->id)->pluck('id')->all();

    expect($mineFieldIds)->toHaveCount(5)
        ->and($theirFieldIds)->toHaveCount(5)
        ->and(array_column(ProjectSubmission::query()->findOrFail($mine)->answers, 'field_id'))->toBe(
            FinalProjectField::query()->where('final_project_id', $this->project->id)->ordered()->pluck('id')->all(),
        )
        ->and(array_intersect(array_column(ProjectSubmission::query()->findOrFail($theirs)->answers, 'field_id'), $mineFieldIds))->toBe([]);
});

it('D-121: الرجوع عن الترحيل يعيد الجدول كما كان، والترحيل يُعاد بعده', function (): void {
    fieldsMigration()->up();

    expect(Schema::hasTable('final_project_fields'))->toBeTrue()
        ->and(Schema::hasColumn('project_submissions', 'answers'))->toBeTrue();

    fieldsMigration()->down();

    expect(Schema::hasTable('final_project_fields'))->toBeFalse()
        ->and(Schema::hasColumn('project_submissions', 'answers'))->toBeFalse()
        ->and(Schema::hasColumn('project_submissions', 'live_url'))->toBeTrue();

    fieldsMigration()->up();

    expect(FinalProjectField::query()->where('final_project_id', $this->project->id)->count())->toBe(5);
});
