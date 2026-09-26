<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations a person starts with another person (D-118).
 *
 * Until now every thread belonged to a cohort — the three the provisioner
 * gives each trainee. A conversation with the general supervisor or with the
 * system administrators' inbox belongs to none, so `cohort_id` becomes
 * nullable; every cohort thread keeps its cohort.
 *
 *   `inbox`    — 'system_admin' for a conversation with the system
 *                administrators' shared inbox: every system administrator
 *                reads it and any of them replies, including one added later.
 *   `pair_key` — one conversation per pair of people, enforced by the
 *                database rather than by a check two requests could both pass:
 *                the two account ids in order for a direct conversation, and
 *                'system_admin:' + the supervisor's id for an inbox one.
 *
 * The down migration removes the conversations that have no cohort — the
 * feature it removes — before the column is made required again; nothing
 * else is touched.
 *
 * @see BR-22 · PRD §9.13 · D-118
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table): void {
            $table->uuid('cohort_id')->nullable()->change();
            $table->string('inbox', 20)->nullable()->after('type');
            $table->string('pair_key', 80)->nullable()->after('inbox');

            $table->index('inbox');
            $table->unique('pair_key');
        });
    }

    public function down(): void
    {
        DB::table('threads')->whereNull('cohort_id')->delete();

        Schema::table('threads', function (Blueprint $table): void {
            $table->dropUnique(['pair_key']);
            $table->dropIndex(['inbox']);
            $table->dropColumn(['inbox', 'pair_key']);
        });

        Schema::table('threads', function (Blueprint $table): void {
            $table->uuid('cohort_id')->nullable(false)->change();
        });
    }
};
