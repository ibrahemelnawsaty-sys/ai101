<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The facts the platform may not have yet.
 *
 * WHY THIS MIGRATION EXISTS
 * `profiles` demanded eleven facts at INSERT time: four Arabic name parts, four
 * Latin ones, the mobile number and the gender. That was true while the only
 * door was self-registration, where the trainee filled the whole form before an
 * account existed. Two doors now open before the person has typed anything:
 *
 *   · the bulk import, whose sheet carries the Arabic name, the address and the
 *     mobile number — and where a name may be two or three parts, not four
 *     (D-85);
 *   · the invitation, which knows the Arabic name and the address and NOTHING
 *     else until the invited person follows their link and fills the rest.
 *
 * A NOT NULL column cannot hold "not yet". The alternative — writing an empty
 * string — makes every reader carry a second meaning for '' and makes
 * `profiles.phone` unique on a value that repeats.
 *
 * `first_name_ar` STAYS REQUIRED. Every screen that names a person starts from
 * it, and no door creates an account without it.
 *
 * THE MOBILE NUMBER KEEPS ITS UNIQUE INDEX. MySQL and SQLite both allow any
 * number of NULLs under a unique index; two people with a number still cannot
 * share it.
 *
 * DOWN() RESTORES NOT NULL AND WILL REFUSE ON A DATABASE THAT HAS USED THIS.
 * That is the honest behaviour: rolling back means the empty values have to be
 * dealt with first, and inventing a name or a mobile number to make a rollback
 * succeed would be worse than failing loudly (CONSTITUTION art. 29).
 *
 * @see PRD §4.2, §9.2.1 · D-85
 */
return new class extends Migration
{
    /** @var list<string> */
    private const RELAXED = [
        'second_name_ar',
        'third_name_ar',
        'last_name_ar',
        'first_name_en',
        'second_name_en',
        'third_name_en',
        'last_name_en',
        'gender',
    ];

    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            foreach (self::RELAXED as $column) {
                $table->string($column, $column === 'gender' ? 10 : 40)->nullable()->change();
            }

            $table->string('phone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            foreach (self::RELAXED as $column) {
                $table->string($column, $column === 'gender' ? 10 : 40)->nullable(false)->change();
            }

            $table->string('phone', 20)->nullable(false)->change();
        });
    }
};
