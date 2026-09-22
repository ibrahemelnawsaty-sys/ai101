<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A session's coordinator, delivery mode and physical location (D-105).
 *
 * WHY THESE COLUMNS EXIST
 * Every session was implicitly online: `zoom_url` was the only meeting-related
 * field, with no way to record that a session actually happens in a room. The
 * general supervisor now chooses a delivery mode per session — a link for an
 * online one, a place, a map link and an optional room for an in-person one —
 * and may assign a coordinator, separate from the trainer, to run its
 * attendance.
 *
 * `delivery_mode` defaults to `online` so every existing session keeps reading
 * exactly as it did: a Zoom link, nothing else. The three location columns
 * stay NULLABLE regardless of mode, mirroring `zoom_url`'s own long-standing
 * behaviour: a session may be saved before its room is booked, and the screen
 * shows a "missing" pill rather than blocking the save (art. 7).
 *
 * `coordinator_id` mirrors `trainer_id` exactly: nullable, `nullOnDelete`, and
 * only ever set to an account already enrolled in the cohort with
 * `role_in_cohort = 'coordinator'` — the FormRequest enforces that half.
 *
 * @see PRD §9.8, §9.10 · CONSTITUTION Article 29
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->foreignUuid('coordinator_id')->nullable()->after('trainer_id')
                ->constrained('users')->nullOnDelete();
            $table->string('delivery_mode', 20)->default('online')->after('coordinator_id');
            $table->string('location_name', 200)->nullable()->after('delivery_mode');
            $table->string('location_map_url', 500)->nullable()->after('location_name');
            $table->string('room_name', 120)->nullable()->after('location_map_url');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropColumn(['delivery_mode', 'location_name', 'location_map_url', 'room_name']);
            $table->dropConstrainedForeignId('coordinator_id');
        });
    }
};
