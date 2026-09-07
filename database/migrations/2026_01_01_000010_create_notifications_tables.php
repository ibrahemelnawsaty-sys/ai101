<?php

/**
 * In-app notifications and their per-type preferences.
 *
 * This is the platform's OWN notifications table as fixed by PROJECT-CONTRACT
 * §4 (user_id, type, title, body, link, is_read, read_at, channel). It is NOT
 * Laravel's polymorphic DatabaseNotification schema, so the `database`
 * notification channel must not be used against this table.
 *
 * Index (user_id, is_read) is mandatory (PRD §7.7) — it backs the unread badge.
 *
 * @see PRD §7.6, §7.7 · BR-34 · PROJECT-CONTRACT §4
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->string('channel', 20)->default('in_app');
            $table->timestamps();

            $table->index(['user_id', 'is_read']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 60);
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
