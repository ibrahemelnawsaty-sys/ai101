<?php

/**
 * The training kit and the ten-step journey.
 *
 * `file_url` holds the STORAGE PATH of an uploaded file, not a public address:
 * uploads live outside the web root and are served only through a temporary
 * signed link valid for 15 minutes, so a directly usable URL must never be
 * persisted here (Constitution art. 22, PROJECT-CONTRACT §11). The column keeps
 * the PRD §7.6 name because App\Models\Resource is written against it.
 * Genuinely external material keeps its own `external_url` column.
 *
 * Journey state is derived from real data and completed automatically — there
 * is no manual marking by a participant (BR-21). The unique key
 * (user_id, journey_step_id) keeps exactly one state row per step per user.
 *
 * @see PRD §7.6, §9.7 · BR-20, BR-21 · PROJECT-CONTRACT §4, §9
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $table->foreignUuid('week_id')->nullable()->constrained('weeks')->nullOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('sessions')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('type', 20)->default('file');
            $table->string('file_url', 500)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cohort_id', 'type']);
            $table->index('week_id');
        });

        Schema::create('journey_steps', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $table->unsignedTinyInteger('index');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('type', 30);
            $table->string('unlock_rule', 60);
            $table->string('related_entity_type', 40)->nullable();
            $table->char('related_entity_id', 36)->nullable();
            $table->string('icon', 60)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cohort_id', 'index']);
        });

        Schema::create('user_journey_states', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('journey_step_id')->constrained('journey_steps')->cascadeOnDelete();
            $table->string('status', 20)->default('locked');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'journey_step_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_journey_states');
        Schema::dropIfExists('journey_steps');
        Schema::dropIfExists('resources');
    }
};
