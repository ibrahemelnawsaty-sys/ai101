<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The final project's guide (D-127): one page per cohort's project and per
 * language, and every version the general supervisor ever saved.
 *
 *   final_project_guides          one row per (project, locale): its two-step
 *                                 state — made available by the supervisor,
 *                                 published by the primary coordinator — and
 *                                 when the trainees, and the cohort's
 *                                 trainers, were first told of it.
 *   final_project_guide_versions  the page itself, one row per save. The
 *                                 highest `version` is what is shown; a
 *                                 restore writes a NEW version, so nothing is
 *                                 ever overwritten or deleted.
 *
 * Every foreign key states its delete rule (Article 29): a guide dies with its
 * project, a version with its guide, and a departed account only empties the
 * "who" columns.
 *
 * @see D-127 · CONSTITUTION Article 29
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('final_project_guides')) {
            Schema::create('final_project_guides', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->foreignUuid('final_project_id')->constrained('final_projects')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->boolean('is_available')->default(false);
                $table->timestamp('available_at')->nullable();
                $table->foreignUuid('available_by')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('is_published')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('announced_at')->nullable();
                $table->timestamp('staff_announced_at')->nullable();
                $table->timestamps();

                $table->unique(['final_project_id', 'locale']);
            });
        }

        if (! Schema::hasTable('final_project_guide_versions')) {
            Schema::create('final_project_guide_versions', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->foreignUuid('final_project_guide_id')->constrained('final_project_guides')->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->longText('html');
                $table->char('sha256', 64);
                $table->unsignedInteger('bytes');
                $table->string('source', 16);
                $table->unsignedInteger('restored_from')->nullable();
                $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();

                $table->unique(['final_project_guide_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('final_project_guide_versions');
        Schema::dropIfExists('final_project_guides');
    }
};
