<?php

/**
 * Assignments and their submissions.
 *
 * BR-19: re-uploading creates a NEW row with an incremented `version`; the
 * previous version is never deleted or overwritten. The unique key
 * (assignment_id, user_id, version) makes an accidental overwrite impossible,
 * and the index (assignment_id, user_id) required by PRD §7.7 is its leftmost
 * prefix plus an explicit index for planner clarity.
 *
 * An assignment that has submissions is never deleted — it is archived
 * (PRD §7.8), hence soft deletes.
 *
 * @see PRD §7.5, §7.7, §7.8 · BR-11, BR-12, BR-19 · PROJECT-CONTRACT §4, §7
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $table->foreignUuid('week_id')->nullable()->constrained('weeks')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description');
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedSmallInteger('max_score');
            $table->timestamp('due_at');
            $table->boolean('allow_late')->default(false);
            $table->boolean('allow_github_link')->default(false);
            $table->unsignedSmallInteger('max_file_size_mb')->default(10);
            $table->unsignedTinyInteger('max_files')->default(3);
            $table->json('attachments')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cohort_id', 'status']);
            $table->index(['week_id', 'is_mandatory']);
            $table->index('due_at');
        });

        Schema::create('submissions', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('files')->nullable();
            $table->string('github_url', 500)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('submitted_at');
            $table->boolean('is_late')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('submitted');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['assignment_id', 'user_id']);
            $table->unique(['assignment_id', 'user_id', 'version']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
        Schema::dropIfExists('assignments');
    }
};
