<?php

declare(strict_types=1);

use App\Services\FinalProject\SubmissionFields;
use App\Services\Time\Clock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The final project's hand-in becomes a list of fields the administrator
 * defines, and every hand-in keeps a copy of what it was asked (D-121).
 *
 * WHAT THIS MIGRATION WRITES, NOT ONLY WHAT IT CREATES
 * On the live platform the first cohort's project already exists and is open,
 * and some participants may already have handed in through the fixed form of
 * D-110. So, after the schema:
 *
 *   1. every existing project that has no fields gets the default set
 *      (SubmissionFields::DEFAULTS) — the owner asked for exactly this on the
 *      current first cohort;
 *   2. every existing hand-in is copied into `answers` against those fields,
 *      from the columns the fixed form wrote. Those columns are READ, never
 *      changed or dropped (CONSTITUTION Article 13, item 15), so the copy can
 *      always be checked against its source.
 *
 * Both run on the query builder, never on models, and hand-ins are walked by id
 * in chunks (`lazyById`), because the walk writes the very column it filters on.
 *
 * ROLLING BACK
 * down() drops the table and the column and leaves every earlier column as it
 * found it, so rows handed in BEFORE this migration lose nothing. Rows handed
 * in AFTER it hold their content in `answers` alone, and rolling back drops
 * it: take the database backup first (deploy/backup.md).
 *
 * @see BR-19, BR-31 · FR-PROJ-10 · PRD §9.14.2 · D-110, D-121 · CONSTITUTION Article 29
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_project_fields', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('final_project_id')->constrained('final_projects')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('label', 160);
            $table->text('description')->nullable();
            $table->json('tips')->nullable();
            $table->boolean('is_required')->default(true);
            $table->json('accepted_formats')->nullable();
            $table->unsignedInteger('max_kilobytes')->nullable();
            $table->unsignedTinyInteger('max_files')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['final_project_id', 'position']);
        });

        Schema::table('project_submissions', function (Blueprint $table): void {
            $table->json('answers')->nullable()->after('files');
        });

        $this->carryExistingProjectsAcross();
    }

    public function down(): void
    {
        Schema::table('project_submissions', function (Blueprint $table): void {
            $table->dropColumn('answers');
        });

        Schema::dropIfExists('final_project_fields');
    }

    private function carryExistingProjectsAcross(): void
    {
        $at = Clock::now();

        $projectIds = DB::table('final_projects')->orderBy('id')->pluck('id');

        foreach ($projectIds as $projectId) {
            $projectId = (string) $projectId;

            if (DB::table('final_project_fields')->where('final_project_id', $projectId)->exists()) {
                continue;
            }

            $rows = SubmissionFields::defaultRows($projectId, $at);

            DB::table('final_project_fields')->insert(array_values($rows));

            DB::table('project_submissions')
                ->where('final_project_id', $projectId)
                ->whereNull('answers')
                ->lazyById(100, 'id')
                ->each(function (stdClass $submission) use ($rows): void {
                    DB::table('project_submissions')
                        ->where('id', $submission->id)
                        ->update([
                            'answers' => json_encode(
                                SubmissionFields::answersFromLegacy($submission, $rows),
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                            ),
                        ]);
                });
        }
    }
};
