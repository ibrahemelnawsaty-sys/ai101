<?php

/**
 * The closing project and its submissions.
 *
 * The project stays locked until a trainer or admin unlocks it; `unlocked_by`
 * and `unlocked_at` record who did so and when. Versioning follows the same
 * rule as assignment submissions (BR-19): a new version, never an overwrite.
 *
 * @see PRD §7.6 · BR-11, BR-19 · PROJECT-CONTRACT §4, §7, §9
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_projects', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('brief');
            $table->json('requirements')->nullable();
            $table->boolean('is_unlocked')->default(false);
            $table->timestamp('unlocked_at')->nullable();
            $table->foreignUuid('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->unsignedSmallInteger('max_score')->default(50);
            $table->json('attachments')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cohort_id', 'is_unlocked']);
        });

        Schema::create('project_submissions', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('final_project_id')->constrained('final_projects')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('files')->nullable();
            $table->string('github_url', 500)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('submitted_at');
            $table->boolean('is_late')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('submitted');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['final_project_id', 'user_id']);
            $table->unique(['final_project_id', 'user_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_submissions');
        Schema::dropIfExists('final_projects');
    }
};
