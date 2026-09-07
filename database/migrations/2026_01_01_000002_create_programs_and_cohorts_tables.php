<?php

/**
 * Programmes, cohorts and cohort membership.
 * All display content lives here and is edited from the admin panel — it is
 * never hard-coded (BR-31, BR-36).
 * `pass_score` and `min_attendance_rate` are the two certificate conditions:
 * both must be met and neither compensates for the other (BR-26).
 * The composite unique key (cohort_id, user_id) makes a duplicate enrolment
 * impossible at the database level (PRD §7.7).
 *
 * @see PRD §7.2, §7.7 · BR-11, BR-26, BR-31, BR-36 · PROJECT-CONTRACT §4
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('name_ar', 160);
            $table->string('name_en', 160);
            $table->string('slug', 160)->unique();
            $table->text('description')->nullable();
            $table->string('banner_url', 500)->nullable();
            $table->json('objectives')->nullable();
            $table->json('target_audience')->nullable();
            $table->json('certificates')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('cohorts', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('program_id')->constrained('programs')->restrictOnDelete();
            $table->string('name', 120);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('capacity')->default(0);
            $table->timestamp('registration_closes_at')->nullable();
            $table->unsignedInteger('seats_taken')->default(0);
            $table->string('status', 20)->default('upcoming');
            $table->unsignedTinyInteger('pass_score')->default(60);
            $table->unsignedTinyInteger('min_attendance_rate')->default(75);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['program_id', 'status']);
            $table->index('start_date');
        });

        Schema::create('enrollments', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role_in_cohort', 20)->default('participant');
            $table->timestamp('enrolled_at');
            $table->string('status', 20)->default('pending');
            $table->decimal('final_score', 5, 2)->nullable();
            $table->decimal('attendance_rate', 5, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cohort_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['cohort_id', 'role_in_cohort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('cohorts');
        Schema::dropIfExists('programs');
    }
};
