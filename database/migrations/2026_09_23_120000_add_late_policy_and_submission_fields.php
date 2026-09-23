<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The final project gains a late-submission policy, and its hand-in gains the
 * three named deliverables the owner asked for plus an optional logo (D-110).
 *
 * `allow_late` mirrors `assignments.allow_late` exactly (BR-18's own switch):
 * off means the hand-in endpoint refuses anything after `due_at`, on means it
 * still accepts it and marks it late. Defaulting to `false` keeps every
 * existing project reading exactly as it did — closed at the deadline.
 *
 * `live_url`, `presentation_file` and `logo_file` are new, separate columns
 * rather than three more entries squeezed into the existing `files` json: the
 * screen now asks for these BY NAME (a working link, a slide deck, an
 * optional logo), not "some files", so the schema says so too. `files` is
 * left in place, unused by the new hand-in flow — the platform is early
 * enough that dropping it would be safe, but dropping a column needs a human
 * sign-off this migration cannot get on its own (CONSTITUTION Article 13's
 * own forbidden list), so it stays and simply stops being written to.
 *
 * @see D-109, D-110 · PRD §9.14 · CONSTITUTION Article 29
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_projects', function (Blueprint $table): void {
            $table->boolean('allow_late')->default(false)->after('due_at');
        });

        Schema::table('project_submissions', function (Blueprint $table): void {
            $table->string('live_url', 500)->nullable()->after('final_project_id');
            $table->json('presentation_file')->nullable()->after('files');
            $table->json('logo_file')->nullable()->after('presentation_file');
        });
    }

    public function down(): void
    {
        Schema::table('project_submissions', function (Blueprint $table): void {
            $table->dropColumn(['live_url', 'presentation_file', 'logo_file']);
        });

        Schema::table('final_projects', function (Blueprint $table): void {
            $table->dropColumn('allow_late');
        });
    }
};
