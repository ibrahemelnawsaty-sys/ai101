<?php

/**
 * Three columns the admin screens bind to that no table carried.
 *
 * `cohorts.requires_approval` is the switch the registration queue depends on:
 * `admin/cohorts.blade.php` edits it and `admin/registrations.blade.php` exists
 * only for the cohorts that have it on (PRD §9.2.3). Until now the checkbox
 * posted a value with nowhere to land.
 *
 * `certificates.tvtc_file_url` is the file the centre uploads once the TVTC
 * issues its own certificate; the participant certificate screen reads it to
 * decide between "ready" and "being issued" (PRD §9.17).
 *
 * `programs.hours` is the training-hours figure printed on the programmes table,
 * in the programme editor and on the certificate itself (PRD §7.2, §9.17).
 *
 * `landing_settings.hero_title` and `.about_body` are two of the three texts the
 * landing editor writes; the third, the hero subtitle, is the existing
 * `hero_text` column that the public page already reads (PRD §9.1, BR-31).
 *
 * `sessions.description` is the session brief: the trainer's session editor
 * writes it and the participant schedule prints it when a session is opened
 * (PRD §9.8).
 *
 * All three are nullable or defaulted, so the migration is safe on a populated
 * table and `down()` drops only what `up()` added (CONSTITUTION art. 29).
 *
 * @see PRD §7.2, §9.2.3, §9.17 · BR-26, BR-31 · CONSTITUTION art. 29
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cohorts', function (Blueprint $table): void {
            $table->boolean('requires_approval')->default(false);
        });

        Schema::table('certificates', function (Blueprint $table): void {
            $table->string('tvtc_file_url', 500)->nullable();
        });

        Schema::table('programs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('hours')->nullable();
        });

        Schema::table('landing_settings', function (Blueprint $table): void {
            $table->string('hero_title', 300)->nullable();
            $table->text('about_body')->nullable();
        });

        Schema::table('sessions', function (Blueprint $table): void {
            $table->text('description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropColumn('description');
        });

        Schema::table('landing_settings', function (Blueprint $table): void {
            $table->dropColumn(['hero_title', 'about_body']);
        });

        Schema::table('programs', function (Blueprint $table): void {
            $table->dropColumn('hours');
        });

        Schema::table('certificates', function (Blueprint $table): void {
            $table->dropColumn('tvtc_file_url');
        });

        Schema::table('cohorts', function (Blueprint $table): void {
            $table->dropColumn('requires_approval');
        });
    }
};
