<?php

/**
 * Internal messaging: threads, their participants and messages.
 *
 * `last_read_at` on thread_participants is the unread counter's only source.
 * Preview mode must never write it (BR-34) — that is enforced in the data
 * access layer, not here.
 *
 * @see PRD §7.6, §9.13 · BR-34 · PROJECT-CONTRACT §4
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threads', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $table->string('type', 20)->default('group');
            $table->string('title', 160)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cohort_id', 'type']);
        });

        Schema::create('thread_participants', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('thread_id')->constrained('threads')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->boolean('is_muted')->default(false);
            $table->timestamps();

            $table->unique(['thread_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('thread_id')->constrained('threads')->cascadeOnDelete();
            $table->foreignUuid('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['thread_id', 'sent_at']);
            $table->index('sender_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('thread_participants');
        Schema::dropIfExists('threads');
    }
};
