<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How early the meeting link appears, decided per session by its trainer.
 *
 * WHY THIS COLUMN EXISTS
 * The window was `LiveController::JOIN_OPENS_BEFORE_START_MINUTES = 15` — a PHP
 * constant. Every session in every cohort opened its link exactly a quarter of
 * an hour early, and the only way to change it for one session was to edit code
 * and deploy. A trainer running a workshop that needs people settled twenty
 * minutes early, or a short clinic that should not open until the minute it
 * starts, had no way to say so.
 *
 * NULLABLE ON PURPOSE. A null means "use the platform default", so every
 * existing session keeps behaving exactly as it does today and nothing has to
 * be backfilled. The constant stays as that default rather than being replaced
 * by a literal somewhere new.
 *
 * @see PRD §9.10 · BR-24 · CONSTITUTION.md Article 29 · D-52
 */
return new class extends Migration
{
    /** Minutes, and a sane ceiling: a link that opens a day early is not a link. */
    private const MAX_MINUTES = 240;

    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('join_opens_minutes')
                ->nullable()
                ->after('zoom_passcode');
        });

        // The ceiling is enforced by the database as well as the FormRequest,
        // because a value that reached the column another way is still a value
        // the screens will honour (art. 9).
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::getConnection()->statement(sprintf(
                'ALTER TABLE `sessions` ADD CONSTRAINT `sessions_join_window_range`
                 CHECK (`join_opens_minutes` IS NULL OR (`join_opens_minutes` >= 0 AND `join_opens_minutes` <= %d))',
                self::MAX_MINUTES,
            ));
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::getConnection()->statement('ALTER TABLE `sessions` DROP CONSTRAINT `sessions_join_window_range`');
        }

        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropColumn('join_opens_minutes');
        });
    }
};
