<?php

/**
 * Attendance records.
 *
 * The composite UNIQUE (session_id, user_id) is the whole point of this table:
 * BR-06 (no duplicate check-in) is enforced by the database itself, so two
 * concurrent requests cannot both succeed. An application-level check alone
 * would lose that race.
 *
 * `ip_address` and `user_agent` are stored with every record for audit
 * (PROJECT-CONTRACT §6). Attendance is never hard-deleted (Constitution
 * art. 13 #11), hence soft deletes. Note the unique key covers the raw columns,
 * so a soft-deleted record still blocks a new one for the same pair — the
 * fail-safe direction (Constitution art. 7).
 *
 * @see PRD §7.4, §7.7 · BR-01 … BR-09 · PROJECT-CONTRACT §4, §6
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('session_id')->constrained('sessions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->string('status', 20)->default('absent');
            $table->boolean('is_manual')->default(false);
            $table->foreignUuid('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('edit_reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['session_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
