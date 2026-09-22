<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every message and every manual reminder an administrator sends to a cohort.
 *
 * It is the history the send screen lists, and it is the cooldown: a second
 * press within minutes finds the first row and is refused, instead of every
 * trainee receiving the same letter twice (D-87).
 *
 * `kind` is one of `message`, `sessions`, `assignments`. `subject` and `body`
 * are the administrator's own words for a message and empty for a reminder,
 * whose words come from the copy files. The counts are what was queued at the
 * moment of sending — a record of the send, not a delivery receipt.
 *
 * A cohort that is deleted takes its history with it; an administrator who is
 * deleted leaves theirs behind, unattributed.
 *
 * @see PRD §9.16, §9.18 · D-87
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('subject', 150)->nullable();
            $table->text('body')->nullable();
            $table->boolean('in_app')->default(true);
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('emails')->default(0);
            $table->timestamp('created_at');

            $table->index(['cohort_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};
