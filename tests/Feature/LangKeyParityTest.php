<?php

declare(strict_types=1);

/**
 * lang/ar and lang/en carry the same keys, in both directions.
 *
 * Nine landing keys existed in Arabic only, and the fallback locale is Arabic —
 * so the day English is switched on, those strings would appear in Arabic
 * inside a left-to-right page, and nothing would fail. The gate reported the
 * gap as a warning only; this is the blocking check (D-78).
 *
 * @see CONSTITUTION Article 15 · PROJECT-CONTRACT §13 · D-78
 */

use Illuminate\Support\Arr;

it('D-78: ملفات الترجمة نفسها في العربية والإنجليزية', function (): void {
    $names = static fn (string $locale): array => array_map('basename', glob(lang_path($locale).'/*.php') ?: []);

    expect($names('en'))->toBe($names('ar'));
});

it('D-78: كل مفتاح عربي موجود بالإنجليزية، وكل مفتاح إنجليزي موجود بالعربية', function (): void {
    $missing = [];
    $orphans = [];

    foreach (glob(lang_path('ar').'/*.php') ?: [] as $file) {
        $name = basename($file);
        $ar = Arr::dot(require $file);
        $en = Arr::dot(require lang_path('en/'.$name));

        foreach (array_keys(array_diff_key($ar, $en)) as $key) {
            $missing[] = $name.': '.$key;
        }

        foreach (array_keys(array_diff_key($en, $ar)) as $key) {
            $orphans[] = $name.': '.$key;
        }
    }

    expect($missing)->toBe([])->and($orphans)->toBe([]);
});
