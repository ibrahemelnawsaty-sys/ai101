<?php

declare(strict_types=1);

use App\Services\FinalProject\ReceiptCodes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every final-project hand-in gets a receipt code — `FP-XXXX-XXXX`, unique —
 * the code its receipt letter, its receipt page and its QR carry (D-122).
 *
 * Hand-ins that already exist are given one too, so the receipt shows on the
 * participant's page for them as well; no letter is sent for them after the
 * fact. They are walked by id (`lazyById`) because the walk writes the very
 * column it filters on.
 *
 * @see FR-NOTIF-15 · D-122 · CONSTITUTION Article 29
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_submissions', function (Blueprint $table): void {
            $table->string('receipt_code', 20)->nullable()->unique()->after('version');
        });

        DB::table('project_submissions')
            ->whereNull('receipt_code')
            ->lazyById(100, 'id')
            ->each(function (stdClass $submission): void {
                DB::table('project_submissions')
                    ->where('id', $submission->id)
                    ->update(['receipt_code' => app(ReceiptCodes::class)->unused()]);
            });
    }

    public function down(): void
    {
        Schema::table('project_submissions', function (Blueprint $table): void {
            $table->dropUnique(['receipt_code']);
            $table->dropColumn('receipt_code');
        });
    }
};
