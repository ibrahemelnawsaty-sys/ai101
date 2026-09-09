<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The line under a trainer's name on the public page.
 *
 * PRD §9.1.1 asks the trainers section for four things about each trainer:
 * a picture, a name, a JOB TITLE and a short biography. Three of them had a
 * column — `profiles.avatar_url`, the four name parts and `profiles.bio` — and
 * the title had none. `education_level` is the nearest thing in the table and it
 * is not the same fact: a degree is a qualification, not what somebody does.
 *
 * Nullable, because the centre fills it in when it has one and the card simply
 * omits the line until then. No default: an invented title on a real person is
 * worse than a missing line.
 *
 * @see PRD §9.1.1 · CONSTITUTION.md Article 29
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->string('job_title', 120)->nullable()->after('education_level');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropColumn('job_title');
        });
    }
};
