<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A picture or a video attached to one line of a support ticket (D-124).
 *
 * The file itself lives on the private disk under a random name; this row is
 * what PrivateFileService::store() returned, and the page shows the file
 * through a signed link that lasts fifteen minutes — its path never leaves the
 * server. A line hidden from the participant hides its files too.
 *
 * @see D-124 · PRD §12.5 · PROJECT-CONTRACT §4 · CONSTITUTION art. 24, art. 29
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_attachments', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('support_ticket_entry_id')->constrained('support_ticket_entries')->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->string('kind', 10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_attachments');
    }
};
