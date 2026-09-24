<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published overrides of the landing page's copy, one row per text.
 *
 * Every sentence a visitor reads on the public pages already has a default in
 * lang/{ar,en}/landing.php. A row here replaces that default for one key, in
 * one language or both, so the centre can rewrite any line of the page from
 * the admin panel without a deploy (BR-31).
 *
 * The shape follows one rule: a language column is NULL when that language
 * follows its default. A row whose two columns are both NULL carries nothing
 * and is deleted rather than kept, so "reset to the original" is the absence
 * of a row, never a frozen copy of the original that stops following it.
 *
 * `key` is the full translation key (`landing.hero.register`), the same string
 * the templates already pass to __(), which is why it is the primary key: the
 * page asks for a key, never for a row id.
 *
 * An administrator who is deleted leaves the copy they wrote behind,
 * unattributed.
 *
 * @see BR-31, BR-36 · PRD §9.1, §9.18 · D-114
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_contents', function (Blueprint $table): void {
            $table->string('key', 191)->primary();
            $table->text('ar')->nullable();
            $table->text('en')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_contents');
    }
};
