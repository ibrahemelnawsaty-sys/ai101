<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line of a support ticket's timeline (D-124): its opening, a reply, a
 * message, a note, a move between levels, a handover, a resolution, a
 * reopening, a closing.
 *
 * `is_internal` hides a line from the participant — the owner's "an option not
 * to show the action to the participant". `actor_id` is null for what the
 * platform itself did (the automatic closing). `from_level`/`to_level` carry a
 * move; `target_id` the coordinator a ticket was handed or returned to.
 *
 * `position` orders the timeline: 1, 2, 3 … per ticket, numbered under the
 * ticket's row lock. Two lines written by one action share their second
 * ("reopened", then the reply), so the time alone cannot order them.
 *
 * @see D-124 · PROJECT-CONTRACT §4 · CONSTITUTION art. 29
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_entries', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->text('body')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->string('from_level', 20)->nullable();
            $table->string('to_level', 20)->nullable();
            $table->foreignUuid('target_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('link_url', 2048)->nullable();
            $table->timestamps();

            $table->unique(['support_ticket_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_entries');
    }
};
