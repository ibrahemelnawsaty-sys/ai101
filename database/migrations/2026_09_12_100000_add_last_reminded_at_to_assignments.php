<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the cohort was last reminded about an assignment.
 *
 * The reminder endpoint claims this column atomically before it sends, so a
 * double click, or a trainer and an administrator pressing at once, reach each
 * non-submitter once — a rate limit per actor lets the second click through
 * (D-68). Nullable: an assignment nobody has reminded about has no stamp.
 *
 * @see PRD §9.11.3 · FR-ASGN-30 · D-68
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table): void {
            $table->timestamp('last_reminded_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table): void {
            $table->dropColumn('last_reminded_at');
        });
    }
};
