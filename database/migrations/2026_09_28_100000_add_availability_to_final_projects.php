<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The final project opens in two steps (D-127): the general supervisor makes
 * it AVAILABLE, then the cohort's primary coordinator PUBLISHES it. Publishing
 * is the existing `is_unlocked` — every lock of BR-15/BR-16 keeps reading that
 * one column — so this adds only the first step and who took it.
 *
 * WHAT IT WRITES, NOT ONLY WHAT IT ADDS
 * A project already open on the day this ships counts as available and
 * published: its `is_unlocked` is copied into `is_available`, with the same
 * moment and the same person. Without that, the first cancel-availability
 * check would read "not available" on a live project mid-hand-in.
 *
 * Safe to run again: each column and the key are added only when missing, the
 * copy only touches rows still unset, and down() drops only what is there.
 *
 * @see D-127 · BR-15, BR-16 · CONSTITUTION Article 29
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('final_projects', 'is_available')) {
            Schema::table('final_projects', function (Blueprint $table): void {
                $table->boolean('is_available')->default(false)->after('is_unlocked');
                $table->timestamp('available_at')->nullable()->after('is_available');
                $table->foreignUuid('available_by')->nullable()->after('available_at');
            });
        }

        if (! $this->hasForeignKey()) {
            Schema::table('final_projects', function (Blueprint $table): void {
                $table->foreign('available_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        DB::table('final_projects')
            ->where('is_unlocked', true)
            ->where('is_available', false)
            ->update([
                'is_available' => true,
                'available_at' => DB::raw('unlocked_at'),
                'available_by' => DB::raw('unlocked_by'),
            ]);
    }

    public function down(): void
    {
        if ($this->hasForeignKey()) {
            Schema::table('final_projects', function (Blueprint $table): void {
                $table->dropForeign(['available_by']);
            });
        }

        if (Schema::hasColumn('final_projects', 'is_available')) {
            Schema::table('final_projects', function (Blueprint $table): void {
                $table->dropColumn(['is_available', 'available_at', 'available_by']);
            });
        }
    }

    private function hasForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('final_projects') as $key) {
            if (($key['columns'] ?? []) === ['available_by']) {
                return true;
            }
        }

        return false;
    }
};
