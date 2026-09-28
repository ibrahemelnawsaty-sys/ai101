<?php

declare(strict_types=1);

/**
 * The guide tables of D-127 on a database in either of the two states a
 * deployment can meet:
 *
 *   - a fresh one — both tables built, the versions table carrying its unique
 *     (guide, version) key under a name MySQL accepts;
 *   - the production one the first, failed deployment left behind — MySQL
 *     cannot roll DDL back, so the versions table was created and stopped
 *     before its unique key (error 1059, a 66-character name).
 *
 * The second is rebuilt here by hand: `migrate:fresh` never exercises a
 * half-built table. The same repair was also run on a real MariaDB (the
 * engine with MySQL's 64-character limit) — see D-130.
 *
 * @see D-127, D-130 · CONSTITUTION Article 29
 */

use App\Models\FinalProjectGuide;
use App\Models\FinalProjectGuideVersion;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function guideTablesMigration(): object
{
    return require database_path('migrations/2026_09_28_105000_create_final_project_guides_tables_with_short_names.php');
}

/** @return list<array{name: string, columns: list<string>, unique: bool}> */
function versionUniqueKeys(): array
{
    return array_values(array_filter(
        Schema::getIndexes('final_project_guide_versions'),
        static fn (array $index): bool => $index['unique'] && $index['columns'] === ['final_project_guide_id', 'version'],
    ));
}

it('D-130: تثبيت جديد: جدول النسخ يحمل قيد (الدليل، رقم النسخة) الفريد باسم لا يتجاوز 64 حرفًا', function (): void {
    $keys = versionUniqueKeys();

    expect($keys)->toHaveCount(1)
        ->and(strlen($keys[0]['name']))->toBeLessThanOrEqual(64);
});

it('D-127, D-130: القيد الفريد يمنع رقم نسخة مكرَّرًا للدليل نفسه — والقاعدة هي الحَكَم', function (): void {
    $guide = FinalProjectGuide::factory()->create();
    FinalProjectGuideVersion::factory()->create(['final_project_guide_id' => $guide->id, 'version' => 1]);

    expect(fn () => FinalProjectGuideVersion::factory()->create(['final_project_guide_id' => $guide->id, 'version' => 1]))
        ->toThrow(QueryException::class);
});

it('D-130: الجدول الذي خلّفه النشر الفاشل بلا قيده الفريد يستعيده الترحيل، والتشغيل الثاني لا يغيّر شيئًا', function (): void {
    // The state the failed deployment left: the table, its foreign keys, no unique key.
    Schema::drop('final_project_guide_versions');
    Schema::create('final_project_guide_versions', function (Blueprint $table): void {
        $table->char('id', 36)->primary();
        $table->foreignUuid('final_project_guide_id')->constrained('final_project_guides')->cascadeOnDelete();
        $table->unsignedInteger('version');
        $table->longText('html');
        $table->char('sha256', 64);
        $table->unsignedInteger('bytes');
        $table->string('source', 16);
        $table->unsignedInteger('restored_from')->nullable();
        $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('created_at')->nullable();
    });

    expect(versionUniqueKeys())->toBeEmpty();

    guideTablesMigration()->up();

    $repaired = versionUniqueKeys();
    expect($repaired)->toHaveCount(1)
        ->and(strlen($repaired[0]['name']))->toBeLessThanOrEqual(64);

    guideTablesMigration()->up();

    expect(versionUniqueKeys())->toHaveCount(1)
        ->and(Schema::getForeignKeys('final_project_guide_versions'))->toHaveCount(2);
});

it('D-130: الترحيل القديم بعد الجديد لا يفعل شيئًا — الجدولان موجودان ولا يُعاد بناؤهما', function (): void {
    $guide = FinalProjectGuide::factory()->create();
    FinalProjectGuideVersion::factory()->create(['final_project_guide_id' => $guide->id, 'version' => 1]);

    (require database_path('migrations/2026_09_28_110000_create_final_project_guides_tables.php'))->up();

    expect(FinalProjectGuideVersion::query()->count())->toBe(1)
        ->and(versionUniqueKeys())->toHaveCount(1);
});

it('D-130: التراجع يزيل الجدولين والتقدّم يعيدهما', function (): void {
    guideTablesMigration()->down();

    expect(Schema::hasTable('final_project_guides'))->toBeFalse()
        ->and(Schema::hasTable('final_project_guide_versions'))->toBeFalse();

    guideTablesMigration()->up();

    expect(Schema::hasTable('final_project_guides'))->toBeTrue()
        ->and(versionUniqueKeys())->toHaveCount(1);
});
