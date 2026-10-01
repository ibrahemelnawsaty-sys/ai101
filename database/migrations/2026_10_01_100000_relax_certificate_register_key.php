<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-149 — the certificate register may hold a replacement row.
 *
 * `certificates` carried `unique(user_id, cohort_id)`. Reissuing a certificate (or issuing again
 * after a revocation) writes a second row for the same person and cohort — the revoked row stays
 * so its public link says «revoked» (BR-25) — and the key refused it every time.
 *
 * The owner chose option A: the unique key becomes a plain index (the lookups by person and cohort
 * keep their index), and «one LIVE certificate per person and cohort» is checked by the server
 * inside the transaction that writes the row, under a lock on the person's row
 * (CertificateController::persist). The serial number and the verification code stay unique.
 *
 * The plain index is added BEFORE the unique one is dropped: on MySQL the foreign key on
 * `user_id` may be resting on the unique index, which then cannot be dropped until another index
 * starts with the same column.
 *
 * @see BR-25, BR-26 · PRD §7.7 · CONSTITUTION art. 29 · D-149
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            $table->index(['user_id', 'cohort_id']);
        });

        Schema::table('certificates', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'cohort_id']);
        });
    }

    /**
     * Putting the key back is only possible while no person holds two rows for a cohort; a
     * replacement is exactly that, so the rollback says so rather than deleting a certificate.
     */
    public function down(): void
    {
        $twice = DB::table('certificates')
            ->select('user_id', 'cohort_id')
            ->groupBy('user_id', 'cohort_id')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($twice) {
            throw new RuntimeException(
                'Cannot restore unique(user_id, cohort_id) on certificates: a person holds more than one row '
                .'for a cohort (a reissued or replaced certificate). Nothing was changed.',
            );
        }

        Schema::table('certificates', function (Blueprint $table): void {
            $table->unique(['user_id', 'cohort_id']);
        });

        Schema::table('certificates', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'cohort_id']);
        });
    }
};
