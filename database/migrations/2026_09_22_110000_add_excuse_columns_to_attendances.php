<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An excuse layered onto an attendance record, separate from `status` (D-106).
 *
 * WHY A SEPARATE LAYER AND NOT A NEW `status` VALUE
 * `AttendanceStatus::Excused` already exists and means something specific: a
 * row a trainer created directly, with no check-in at all. Reusing it for "a
 * late check-in that was later excused" would erase the fact that the person
 * DID arrive, and lose the timestamp the door already recorded. `status` stays
 * the door's timestamp-derived truth (present/late/absent/incomplete);
 * `excused_at`/`excuse_reason`/`excused_by` record a separate, later judgement
 * on top of it, so "late, excused" and "absent, excused" can both be told
 * apart from "excused with no attempt at all" (the pre-existing status value).
 *
 * All three columns stay nullable and unused by any rate calculation until
 * D-26 (the certificate issuance rate weighting) is resolved — this migration
 * only makes the fact representable; PR-3 does not change how a rate is
 * computed.
 *
 * @see D-106, D-26 · CONSTITUTION Article 29
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->timestamp('excused_at')->nullable()->after('edit_reason');
            $table->text('excuse_reason')->nullable()->after('excused_at');
            $table->foreignUuid('excused_by')->nullable()->after('excuse_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('excused_by');
            $table->dropColumn(['excused_at', 'excuse_reason']);
        });
    }
};
