<?php

/**
 * Grades. One evaluation per graded submission.
 *
 * `max_score` is a deliberate snapshot of the graded entity's maximum at the
 * moment of grading. It exists for two reasons:
 *  1. PRD §7.7 demands a CHECK constraint "score does not exceed max_score and
 *     is not below zero" — a CHECK can only reference columns of its own row,
 *     so the ceiling must live here.
 *  2. Later editing of an assignment's max_score must not retroactively
 *     invalidate a grade already awarded (BR-12, BR-14).
 *
 * BR-13 (feedback mandatory, at least 10 characters) is also enforced by a
 * CHECK, not only by the FormRequest.
 *
 * The polymorphic pair (entity_type, entity_id) cannot carry a foreign key;
 * scope enforcement is the Policy layer's job. `user_id` is RESTRICT because a
 * user holding evaluations is never deleted (PRD §7.8).
 *
 * @see PRD §7.5, §7.7, §7.8 · BR-12, BR-13, BR-14 · PROJECT-CONTRACT §4, §7
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('entity_type', 20);
            $table->char('entity_id', 36);
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('score', 5, 2);
            $table->decimal('max_score', 5, 2);
            $table->text('feedback');
            $table->foreignUuid('evaluated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('evaluated_at');
            $table->text('revision_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_type', 'entity_id']);
            $table->index(['user_id', 'entity_type']);
            $table->index('evaluated_by');
        });

        if ($this->supportsCheckConstraints()) {
            DB::statement(
                'ALTER TABLE `evaluations` ADD CONSTRAINT `evaluations_score_range_check` '
                .'CHECK (`score` >= 0 AND `score` <= `max_score`)'
            );

            DB::statement(
                'ALTER TABLE `evaluations` ADD CONSTRAINT `evaluations_feedback_length_check` '
                .'CHECK (CHAR_LENGTH(`feedback`) >= 10)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }

    /**
     * MySQL 8.0.16+ and MariaDB 10.2+ enforce CHECK constraints. SQLite cannot
     * add one after CREATE TABLE, so it is skipped there; the rules stay
     * enforced in the FormRequest layer regardless of driver.
     */
    private function supportsCheckConstraints(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
