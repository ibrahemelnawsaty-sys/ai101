<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cohort's primary coordinator (D-124): the one a support ticket reaches
 * first. Null means "not chosen": a cohort with a single coordinator needs no
 * choice (that coordinator is primary), and App\Services\Cohorts\
 * PrimaryCoordinator is the only reader, so a chosen coordinator who later
 * leaves the cohort is never treated as primary.
 *
 * The account row is never hard-deleted, so the null-on-delete only covers a
 * table cleanup: the column then reads as "not chosen" again.
 *
 * Safe to run again: MySQL commits each schema statement on its own, so a run
 * that stopped part-way may have left the column without its key. Each half is
 * added only when missing, and down() drops only what is there.
 *
 * @see D-124 · CONSTITUTION Article 29
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cohorts', 'primary_coordinator_id')) {
            Schema::table('cohorts', function (Blueprint $table): void {
                $table->foreignUuid('primary_coordinator_id')
                    ->nullable()
                    ->after('min_attendance_rate');
            });
        }

        if (! $this->hasForeignKey()) {
            Schema::table('cohorts', function (Blueprint $table): void {
                $table->foreign('primary_coordinator_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->hasForeignKey()) {
            Schema::table('cohorts', function (Blueprint $table): void {
                $table->dropForeign(['primary_coordinator_id']);
            });
        }

        if (Schema::hasColumn('cohorts', 'primary_coordinator_id')) {
            Schema::table('cohorts', function (Blueprint $table): void {
                $table->dropColumn('primary_coordinator_id');
            });
        }
    }

    private function hasForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('cohorts') as $key) {
            if (($key['columns'] ?? []) === ['primary_coordinator_id']) {
                return true;
            }
        }

        return false;
    }
};
