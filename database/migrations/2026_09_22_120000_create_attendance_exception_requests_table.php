<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A participant's request to be excused for an absence or an unexcused
 * lateness, decided by the cohort's coordinator, trainer or an admin (D-106).
 *
 * One request tracks one decision: once approved or rejected it is terminal —
 * a participant who needs another look submits a new request rather than
 * reopening this one, exactly as a registration decision is never reopened
 * (PRD §9.2.3). Concurrent duplicate PENDING requests for the same attendance
 * row are refused by the service under a row lock, not by a database
 * constraint: a rejected request must still allow a later, better one for the
 * very same attendance record.
 *
 * @see PRD §9.9 · D-106 · CONSTITUTION Article 29
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_exception_requests', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('attendance_id')->constrained('attendances')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->text('decision_reason')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['attendance_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_exception_requests');
    }
};
