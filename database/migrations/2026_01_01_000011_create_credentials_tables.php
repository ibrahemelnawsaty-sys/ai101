<?php

/**
 * Certificates and digital cards — the two publicly verifiable credentials.
 *
 * `serial_number` (format ATHAR-AI101-2026-0001) and `verify_code` are both
 * UNIQUE (PRD §7.7). `verify_code` and `qr_token` are long random signed values,
 * never a user id, because both are reachable from a public page (BR-25).
 *
 * `user_id` and `cohort_id` are RESTRICT on delete: a user holding an issued
 * certificate is never deleted (PRD §7.8). Revocation is a timestamp, never a
 * row deletion (Constitution art. 13 #11).
 *
 * There is deliberately no `revoked_reason` or `override_reason` column: the
 * reason for a revocation, and for an administrator's manual override, belongs
 * in `audit_logs` with the actor and the IP address (Constitution art. 8), and
 * duplicating it here would create a second source for the same fact (art. 6).
 *
 * @see PRD §7.6, §7.7, §7.8, §9.17 · BR-25, BR-26 · PROJECT-CONTRACT §4, §8
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('cohort_id')->constrained('cohorts')->restrictOnDelete();
            $table->string('serial_number', 40)->unique();
            $table->string('verify_code', 64)->unique();
            $table->timestamp('issued_at');
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_url', 500)->nullable();
            $table->decimal('final_score', 5, 2);
            $table->decimal('attendance_rate', 5, 2);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'cohort_id']);
        });

        Schema::create('digital_cards', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $table->string('card_number', 40)->unique();
            $table->string('qr_token', 128)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'cohort_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_cards');
        Schema::dropIfExists('certificates');
    }
};
