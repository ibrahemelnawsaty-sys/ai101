<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this enrolment was last warned that its attendance fell below the
 * certificate threshold. Set on the drop, cleared when the rate climbs back,
 * so each drop is warned once (PRD §9.16.1, D-77).
 *
 * @see PRD §9.16.1 · BR-26 · D-77
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->timestamp('attendance_low_notified_at')->nullable()->after('attendance_rate');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropColumn('attendance_low_notified_at');
        });
    }
};
