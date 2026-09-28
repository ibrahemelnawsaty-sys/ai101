<?php

declare(strict_types=1);

/**
 * MySQL refuses an index, key or constraint name longer than 64 characters
 * (error 1059). SQLite — which the suite runs on — accepts any length, so a
 * name that is too long passes every test and fails only on the live server.
 * That is exactly how `final_project_guide_versions_final_project_guide_id_version_unique`
 * (66 characters) reached the first D-127 deployment and stopped it half way.
 *
 * This holds every index the migrations produce, and the conventional name
 * Laravel gives every foreign key, to MySQL's limit — on the engine the suite
 * does run on, so the check is never skipped.
 *
 * @see D-130 · CONSTITUTION Article 29
 */

use Illuminate\Support\Facades\Schema;

it('D-130: no index or foreign-key name the migrations produce is longer than MySQL allows', function (): void {
    $limit = 64;
    $tooLong = [];

    foreach (Schema::getTables() as $table) {
        $tableName = $table['name'];

        foreach (Schema::getIndexes($tableName) as $index) {
            if (strlen((string) $index['name']) > $limit) {
                $tooLong[] = $tableName.' · index '.$index['name'].' ('.strlen((string) $index['name']).')';
            }
        }

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            $conventional = $tableName.'_'.implode('_', $foreignKey['columns']).'_foreign';

            if (strlen($conventional) > $limit) {
                $tooLong[] = $tableName.' · foreign key '.$conventional.' ('.strlen($conventional).')';
            }
        }
    }

    expect($tooLong)->toBe([]);
});
