<?php

declare(strict_types=1);

/**
 * A table's `deleted_at` column and its model's SoftDeletes trait agree.
 *
 * WHY THIS SUITE EXISTS
 * Seventeen tables were migrated with `softDeletes()` while their models never
 * took the trait — so the column is written by nothing, read by nothing, and a
 * `->delete()` on any of them removes the row for good. For certificates and
 * digital cards the source settles it: `revoked_at` is their only removal
 * state (D-78). For the rest — attendances and evaluations among them, which
 * the constitution forbids deleting for real — it is an open decision (D-79).
 *
 * Until it is decided, the list below is the whole of the drift, named. A new
 * table that drifts fails here; so does one fixed without leaving the list.
 *
 * @see PROJECT-CONTRACT §4 · CONSTITUTION Art. 13 · D-53, D-78, D-79
 */

use App\Models\Certificate;
use App\Models\DigitalCard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

/** Tables with an unused deleted_at, awaiting D-79 (credentials: D-78). */
const DELETED_AT_WITHOUT_TRAIT = [
    'assignments', 'attendances', 'certificates', 'cohorts', 'digital_cards', 'enrollments',
    'evaluations', 'final_projects', 'journey_steps', 'profiles', 'programs', 'project_submissions',
    'resources', 'sessions', 'submissions', 'threads', 'weeks',
];

it('D-79: عمود deleted_at وسمة SoftDeletes يتّفقان — والاختلاف الحالي كلّه مسمًّى', function (): void {
    $drift = [];

    foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract() || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        /** @var Model $model */
        $model = new $class;
        $column = Schema::hasColumn($model->getTable(), 'deleted_at');
        $trait = array_key_exists(SoftDeletes::class, class_uses_recursive($class));

        if ($column !== $trait) {
            $drift[] = $model->getTable();
        }
    }

    sort($drift);

    expect($drift)->toBe(DELETED_AT_WITHOUT_TRAIT);
});

it('D-78: الشهادة والبطاقة لا تُحذفان ناعمًا — revoked_at حالة إزالتهما الوحيدة', function (): void {
    foreach (['certificates', 'digital_cards'] as $table) {
        expect(Schema::hasColumn($table, 'revoked_at'))->toBeTrue();
    }

    expect(class_uses_recursive(Certificate::class))->not->toHaveKey(SoftDeletes::class)
        ->and(class_uses_recursive(DigitalCard::class))->not->toHaveKey(SoftDeletes::class);
});
