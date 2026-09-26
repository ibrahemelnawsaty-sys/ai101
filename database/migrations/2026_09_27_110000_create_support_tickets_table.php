<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support tickets (D-124).
 *
 * A participant opens one; it reaches the cohort's primary coordinator, climbs
 * one level at a time when they cannot solve it, and comes back down with the
 * answer. `level` says which level holds it now; `assignee_id` is the
 * coordinator it sits with at the coordinator level. `reached_system_admin_at`
 * is written the first time it climbs to the system administrator, who keeps
 * reading it afterwards ("what was transferred to them").
 *
 * The one writer is App\Services\Tickets\TicketWorkflow; the number is random
 * (TK-XXXX-XXXX) so it names a ticket without counting the others.
 *
 * @see D-124 · PROJECT-CONTRACT §4 · CONSTITUTION art. 29
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('number', 12)->unique();
            $table->foreignUuid('opener_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('cohort_id')->nullable()->constrained('cohorts')->nullOnDelete();
            $table->string('category', 20);
            $table->string('subject', 150);
            $table->string('status', 20)->default('open');
            $table->string('level', 20)->default('coordinator');
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reached_system_admin_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_activity_at');
            $table->timestamps();

            $table->index(['opener_id', 'last_activity_at']);
            $table->index(['cohort_id', 'status']);
            $table->index(['level', 'status']);
            $table->index(['assignee_id', 'status']);
            $table->index(['status', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
