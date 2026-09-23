<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A session's meeting link stops being Zoom-only in name, and gains a platform
 * (D-105 amendment, 23 September 2026).
 *
 * `zoom_url`/`zoom_passcode` were named for the only platform the column ever
 * held; `StoreSessionRequest`/`UpdateSessionRequest` already validate the
 * incoming field as `meeting_url`/`meeting_passcode` and only wrote it into the
 * old column names, so the rename below just finishes what the request layer
 * already assumed. `platform` records which video service the link opens —
 * Zoom, Google Meet or Microsoft Teams — nullable because an in-person session
 * has none. Nothing here changes how the join link is delivered (still a
 * signed redirect, never embedded — BR-24): only its label and the column it
 * is read from change.
 *
 * @see PRD §9.8, §9.10 · CONSTITUTION Article 29
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->renameColumn('zoom_url', 'meeting_url');
            $table->renameColumn('zoom_passcode', 'meeting_passcode');
        });

        Schema::table('sessions', function (Blueprint $table): void {
            $table->string('platform', 20)->nullable()->after('delivery_mode');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropColumn('platform');
        });

        Schema::table('sessions', function (Blueprint $table): void {
            $table->renameColumn('meeting_url', 'zoom_url');
            $table->renameColumn('meeting_passcode', 'zoom_passcode');
        });
    }
};
