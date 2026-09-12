<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this member was last e-mailed about this conversation. A new message
 * is e-mailed to an offline member at most once per window, however many
 * arrive in it — the claim is a conditional update on this column (D-83,
 * AMB-14).
 *
 * @see PRD §9.13.2, §9.16.1 · FR-NOTIF-22 · D-83
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_participants', function (Blueprint $table): void {
            $table->timestamp('last_emailed_at')->nullable()->after('is_muted');
        });
    }

    public function down(): void
    {
        Schema::table('thread_participants', function (Blueprint $table): void {
            $table->dropColumn('last_emailed_at');
        });
    }
};
