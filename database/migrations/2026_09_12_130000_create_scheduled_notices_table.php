<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per time-driven notice already sent — a session tomorrow, in an
 * hour, starting now; an assignment due in two days, in six hours.
 *
 * The unique key is the claim: the run that inserts the row sends the notice,
 * every other run inserts nothing and sends nothing. The target instant is in
 * the key so a session moved to another time is reminded again (D-83).
 *
 * @see PRD §9.16.1 · FR-NOTIF-10, FR-NOTIF-11, FR-NOTIF-14 · D-83
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_notices', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('kind', 40);
            $table->char('subject_id', 36);
            $table->timestamp('target_at');
            $table->timestamp('created_at');

            $table->unique(['kind', 'subject_id', 'target_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_notices');
    }
};
