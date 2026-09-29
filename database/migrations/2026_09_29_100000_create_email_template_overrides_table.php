<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published overrides of an e-mail template's subject and body, one row per text.
 *
 * Every letter's words already have a default in lang/{ar,en}/emails.php. A row
 * here replaces that default for one key, so the system administrator can
 * reword a letter from the admin panel without a deploy (BR-31) — the same
 * shape and the same rule as the landing page's copy (D-114):
 *
 *  · a language column is NULL when that language follows its default;
 *  · a row whose two columns are both NULL carries nothing and is deleted, so
 *    "back to the original" is the absence of a row, never a frozen copy of the
 *    original that stops following the file;
 *  · `key` is the full translation key (`emails.welcome.subject`), the string
 *    every letter already passes to __(), which is why it is the primary key.
 *
 * Only a template's SUBJECT and BODY are ever written here. Which keys may be
 * is decided by the file and by App\Services\Mail\EmailTemplates, not by this
 * table: a stale or foreign row is ignored when the group loads.
 *
 * An administrator who is deleted leaves the copy they wrote behind,
 * unattributed.
 *
 * @see BR-31, BR-36 · PRD §9.16, §9.18 · D-114, D-136
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_template_overrides', function (Blueprint $table): void {
            $table->string('key', 191)->primary();
            $table->text('ar')->nullable();
            $table->text('en')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_overrides');
    }
};
