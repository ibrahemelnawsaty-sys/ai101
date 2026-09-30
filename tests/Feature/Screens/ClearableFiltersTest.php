<?php

declare(strict_types=1);

/**
 * Review of Phase 2 (2-C) — a filter can be taken OFF, not only put on.
 *
 * The filters now narrow the list on the server. Their selects listed only the
 * real values, and nothing in the listbox un-chooses one: once "Week 2" was
 * picked the person kept a narrowed list and no control that widened it
 * (the sidebar link, or the "clear" link of an empty result, were the only
 * ways back). A select that lives in a GET filter form now leads with an
 * "All" entry that posts an empty value — which every list screen reads as
 * "no filter" (ListFilter) — except where the page REQUIRES a choice.
 *
 * @see FR-NOTIF-02, FR-RES-05, FR-LIVE-08, FR-ATT-26, FR-SCHED-13, FR-ADMIN-12 · CONSTITUTION art. 16 · D-136
 */

use App\Support\ListFilter;
use App\View\Components\Ui\Select;
use Illuminate\Http\Request;

it('FR-SCHED-13: قائمة المرشّح تبدأ بخيار «الكل» بقيمة فارغة، وقائمة النموذج العادية لا تتغيّر', function (): void {
    $options = [['value' => 'a', 'label' => 'ألف'], ['value' => 'b', 'label' => 'باء']];

    $filter = new Select(name: 'type', options: $options, clearable: true);

    expect($filter->items)->toBe([['value' => '', 'label' => __('ui.select.all')], ...$options])
        ->and($filter->placeholderText)->toBe(__('ui.select.all'));

    // A form field keeps exactly the options it was given.
    $field = new Select(name: 'type', options: $options);

    expect($field->items)->toBe($options);

    // The empty entry takes the screen's own words when it has some.
    $named = new Select(name: 'week_id', options: $options, clearable: true, placeholder: 'بلا أسبوع');

    expect($named->items[0])->toBe(['value' => '', 'label' => 'بلا أسبوع']);
});

it('FR-SCHED-13: القيمة الفارغة التي يرسلها خيار «الكل» تُقرأ عند الخادم «بلا مرشّح» لا خطأ ولا نتيجة فارغة', function (): void {
    $request = Request::create('/x', 'GET', ['type' => '', 'week' => '']);

    expect(ListFilter::oneOf($request, 'type', ['a', 'b']))->toBeNull()
        ->and(ListFilter::text($request, 'week'))->toBeNull();
});

it('FR-NOTIF-02: كل قائمة داخل نموذج مرشّح (GET) قابلة للمسح، إلا ما تُلزم الشاشة باختياره', function (): void {
    // Where the page cannot work without a choice, "All" would be a lie: the
    // roster needs a session, the final-project screen a cohort, and the
    // trainer's reports a cohort of their own. (The grading board is NOT on this
    // list: with no assignment it lists every assignment's hand-ins, which is
    // "All" — a placeholder saying "choose" above a full list said the opposite.)
    $required = [
        'trainer/attendance.blade.php' => ['session'],
        'admin/final-project.blade.php' => ['cohort'],
        'trainer/reports.blade.php' => ['cohort'],
    ];

    $missing = [];

    foreach (glob(resource_path('views/{admin,trainer,participant}/{,*/}*.blade.php'), GLOB_BRACE) ?: [] as $file) {
        $relative = str_replace(resource_path('views').'/', '', $file);
        $html = (string) file_get_contents($file);

        preg_match_all('/<form\b[^>]*method="GET"[^>]*>(.*?)<\/form>/s', $html, $forms);

        foreach ($forms[1] as $form) {
            preg_match_all('/<x-ui\.select\b.*?\/>/s', $form, $selects);

            foreach ($selects[0] as $select) {
                preg_match('/\bname="([^"]+)"/', $select, $name);

                if (! str_contains($select, 'clearable') && ! in_array($name[1] ?? '', $required[$relative] ?? [], true)) {
                    $missing[] = $relative.' → '.($name[1] ?? '?');
                }
            }
        }
    }

    expect($missing)->toBe([]);
});

it('FR-NOTIF-02: النص المرسوم من الخادم قبل تشغيل Alpine هو القيمة المختارة — لا «الكل» تظهر لحظة فوق مرشّح مفعَّل', function (): void {
    $options = [['value' => 'a', 'label' => 'ألف'], ['value' => 'b', 'label' => 'باء']];

    expect((new Select(name: 'type', options: $options, value: 'b', clearable: true))->initialLabel)->toBe('باء')
        ->and((new Select(name: 'type', options: $options, value: '', clearable: true))->initialLabel)->toBe(__('ui.select.all'))
        // A value the list never offered shows the placeholder, not a stale word.
        ->and((new Select(name: 'type', options: $options, value: 'zzz'))->initialLabel)->toBe(__('ui.select.placeholder'));
});
