<?php

declare(strict_types=1);

/**
 * The refusals a screen prints once, in one alert (D-144).
 *
 * @see CONSTITUTION art. 5, art. 17 · D-144
 */

use App\Presenters\Support\FormErrors;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

it('D-144: كل رسائل الرفض تُعرض ما لم يكن لأصحابها سطر خطأ تحت خانتهم', function (): void {
    $bag = new ViewErrorBag;
    $bag->put('default', new MessageBag([
        'user_id' => ['Tick someone first.'],
        'cohort_id' => ['Already issued.'],
        'override_reason' => ['Too short.'],
    ]));

    expect(FormErrors::apart($bag, ['override_reason']))->toBe(['Tick someone first.', 'Already issued.'])
        ->and(FormErrors::apart($bag))->toBe(['Tick someone first.', 'Already issued.', 'Too short.']);
});

it('D-144: الرسالة المكرّرة تُقال مرة واحدة', function (): void {
    $bag = new MessageBag(['user_id.0' => ['Not valid.'], 'user_id.1' => ['Not valid.']]);

    expect(FormErrors::apart($bag))->toBe(['Not valid.']);
});

it('D-144: بلا أخطاء أو بشيء ليس حقيبة أخطاء لا يُعرض شيء', function (): void {
    expect(FormErrors::apart(null))->toBe([])
        ->and(FormErrors::apart(new ViewErrorBag))->toBe([])
        ->and(FormErrors::apart('oops'))->toBe([]);
});
