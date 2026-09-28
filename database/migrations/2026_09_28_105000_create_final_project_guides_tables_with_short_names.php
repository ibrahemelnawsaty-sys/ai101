<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guide tables of D-127, with an index name MySQL accepts (D-130).
 *
 * WHAT WENT WRONG. `2026_09_28_110000_create_final_project_guides_tables`
 * asked Laravel for `unique(['final_project_guide_id', 'version'])` on
 * `final_project_guide_versions`. The name Laravel derives from that is
 * `final_project_guide_versions_final_project_guide_id_version_unique` — 66
 * characters, and MySQL stops at 64 (error 1059). SQLite, which the tests run
 * on, has no such limit, so nothing failed until the first deployment. MySQL
 * cannot roll DDL back, so that run left BOTH tables behind and the versions
 * table without its unique key.
 *
 * WHY A NEW FILE. A merged migration is never edited (Article 29). This one
 * sorts BEFORE the failed one, so every database meets it first:
 *
 *   - a fresh database gets both tables here, with the short name, and the
 *     old migration's `hasTable` guards then find them and do nothing;
 *   - the production database the failed run left half-built keeps its two
 *     tables, and gets the missing unique key (and any missing foreign key)
 *     added — again leaving the old migration a no-op.
 *
 * Nothing is ever dropped or rewritten here, and running it twice changes
 * nothing.
 *
 * @see D-127, D-130 · CONSTITUTION Article 29
 */
return new class extends Migration
{
    /** The old name was 66 characters; MySQL's limit is 64. */
    private const VERSIONS_UNIQUE = 'fp_guide_versions_guide_version_unique';

    public function up(): void
    {
        if (! Schema::hasTable('final_project_guides')) {
            Schema::create('final_project_guides', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->foreignUuid('final_project_id')->constrained('final_projects')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->boolean('is_available')->default(false);
                $table->timestamp('available_at')->nullable();
                $table->foreignUuid('available_by')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('is_published')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('announced_at')->nullable();
                $table->timestamp('staff_announced_at')->nullable();
                $table->timestamps();

                $table->unique(['final_project_id', 'locale']);
            });
        }

        if (! Schema::hasTable('final_project_guide_versions')) {
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

                $table->unique(['final_project_guide_id', 'version'], self::VERSIONS_UNIQUE);
            });

            return;
        }

        $this->completeVersionsTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('final_project_guide_versions');
        Schema::dropIfExists('final_project_guides');
    }

    /**
     * The versions table as the failed run left it: created, but stopped
     * before the unique key. Each piece is added only when it is missing.
     */
    private function completeVersionsTable(): void
    {
        $versions = 'final_project_guide_versions';

        if (! $this->hasUniqueKey($versions, ['final_project_guide_id', 'version'])) {
            // A duplicate (guide, version) pair stops this with MySQL's own
            // "Duplicate entry" — refused rather than papered over (Article 7).
            Schema::table($versions, function (Blueprint $table): void {
                $table->unique(['final_project_guide_id', 'version'], self::VERSIONS_UNIQUE);
            });
        }

        if (! $this->hasForeignKey($versions, 'final_project_guide_id', 'final_project_guides')) {
            Schema::table($versions, function (Blueprint $table): void {
                $table->foreign('final_project_guide_id')->references('id')->on('final_project_guides')->cascadeOnDelete();
            });
        }

        if (! $this->hasForeignKey($versions, 'created_by', 'users')) {
            Schema::table($versions, function (Blueprint $table): void {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /** @param  list<string>  $columns */
    private function hasUniqueKey(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }

    private function hasForeignKey(string $table, string $column, string $references): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column] && $foreignKey['foreign_table'] === $references) {
                return true;
            }
        }

        return false;
    }
};
