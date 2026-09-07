<?php

/**
 * Weeks and live sessions.
 *
 * `date` + `start_time` + `end_time` hold the session in Asia/Riyadh WALL-CLOCK
 * form exactly as PRD §7.3 specifies. They are NOT instants: the single source
 * that composes them into a UTC instant is App\Services\Time\Clock, and every
 * attendance window is derived from that composition (BR-07).
 *
 * Mandatory constraints (PRD §7.7):
 *  - index (cohort_id, date) for the schedule queries.
 *  - CHECK end_time > start_time.
 *
 * A session with attendance records is never deleted — it is cancelled with a
 * reason (PRD §7.8), hence soft deletes plus the `cancelled` status.
 *
 * @see PRD §7.3, §7.7, §7.8 · BR-07 · PROJECT-CONTRACT §4, §6
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weeks', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $table->unsignedTinyInteger('index');
            $table->string('title', 160);
            $table->json('objectives')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cohort_id', 'index']);
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $table->foreignUuid('week_id')->nullable()->constrained('weeks')->nullOnDelete();
            $table->string('title', 160);
            $table->string('topic', 200)->nullable();
            $table->string('type', 20)->default('training');
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignUuid('trainer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('zoom_url', 500)->nullable();
            $table->string('zoom_passcode', 60)->nullable();
            $table->string('recording_url', 500)->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cohort_id', 'date']);
            $table->index(['cohort_id', 'type']);
            $table->index('status');
        });

        if ($this->supportsCheckConstraints()) {
            DB::statement(
                'ALTER TABLE `sessions` ADD CONSTRAINT `sessions_time_order_check` '
                .'CHECK (`end_time` > `start_time`)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('weeks');
    }

    /**
     * MySQL 8.0.16+ and MariaDB 10.2+ enforce CHECK constraints. SQLite cannot
     * add one after CREATE TABLE, so the constraint is skipped there and the
     * rule is additionally enforced in the FormRequest layer.
     */
    private function supportsCheckConstraints(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
