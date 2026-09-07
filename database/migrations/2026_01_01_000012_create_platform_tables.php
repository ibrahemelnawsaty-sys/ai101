<?php

/**
 * Landing settings, the audit trail, e-mail tokens and impersonation sessions.
 *
 * `audit_logs` is append-only (Constitution art. 8): it carries `created_at`
 * only — there is deliberately no `updated_at` and no `deleted_at`, so an
 * Eloquent update or delete has nowhere to land. The database user's grants on
 * this table must exclude UPDATE and DELETE.
 *
 * `email_tokens` stores only a hash of the token, is single-use (`used_at`) and
 * expires (`expires_at`) — the raw token never touches the database.
 *
 * `impersonation_sessions` records every preview session; the 30-minute cap
 * (BR-33 … BR-35) is enforced in the service layer against `started_at`. The IP
 * address and user agent of a preview are NOT repeated here — art. 23 requires
 * the start, the end and every tab change to be written to `audit_logs`, and
 * that is where those two facts live (art. 6, art. 8).
 *
 * @see PRD §7.6, §9.20 · BR-25, BR-31, BR-33, BR-34, BR-35, BR-36 · PROJECT-CONTRACT §4
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_settings', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('cohort_id')->unique()->constrained('cohorts')->cascadeOnDelete();
            $table->unsignedInteger('seats_remaining_override')->nullable();
            $table->boolean('countdown_enabled')->default(true);
            $table->text('hero_text')->nullable();
            $table->json('faq')->nullable();
            $table->boolean('is_registration_open')->default(true);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('entity_type', 80)->nullable();
            $table->char('entity_id', 36)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at');

            $table->index(['actor_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['action', 'created_at']);
        });

        Schema::create('email_tokens', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('type', 20);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index('expires_at');
        });

        Schema::create('impersonation_sessions', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('admin_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('target_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'started_at']);
            $table->index(['target_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
        Schema::dropIfExists('email_tokens');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('landing_settings');
    }
};
