<?php

declare(strict_types=1);

/**
 * One spelling for how a list screen reads a filter: only a string counts, a
 * value the screen never offered is ignored (not an error, not "no results"),
 * and free text is trimmed and capped.
 *
 * @see CONSTITUTION art. 22 · D-136
 */

use App\Enums\AttendanceStatus;
use App\Support\ListFilter;
use Illuminate\Http\Request;

function filterRequest(array $query): Request
{
    return Request::create('/x', 'GET', $query);
}

it('D-136: نص المرشّح يُقصّ ويُختصر، والفارغ وغير النصّي لا مرشّح', function (): void {
    expect(ListFilter::text(filterRequest(['q' => '  prompt  ']), 'q'))->toBe('prompt')
        ->and(ListFilter::text(filterRequest(['q' => '   ']), 'q'))->toBeNull()
        ->and(ListFilter::text(filterRequest([]), 'q'))->toBeNull()
        ->and(ListFilter::text(filterRequest(['q' => ['a', 'b']]), 'q'))->toBeNull()
        ->and(mb_strlen((string) ListFilter::text(filterRequest(['q' => str_repeat('ا', 500)]), 'q')))
        ->toBe(ListFilter::MAX_TEXT);
});

it('D-136: oneOf يقبل المعروض وحده — غيره لا مرشّح', function (): void {
    $allowed = ['a', 'b'];

    expect(ListFilter::oneOf(filterRequest(['k' => 'a']), 'k', $allowed))->toBe('a')
        ->and(ListFilter::oneOf(filterRequest(['k' => 'c']), 'k', $allowed))->toBeNull()
        ->and(ListFilter::oneOf(filterRequest(['k' => ['a']]), 'k', $allowed))->toBeNull()
        ->and(ListFilter::oneOf(filterRequest([]), 'k', $allowed))->toBeNull();
});

it('D-136: enum يقبل حالات التعداد وحدها', function (): void {
    expect(ListFilter::enum(filterRequest(['s' => 'late']), 's', AttendanceStatus::class))->toBe('late')
        ->and(ListFilter::enum(filterRequest(['s' => 'LATE']), 's', AttendanceStatus::class))->toBeNull()
        ->and(ListFilter::enum(filterRequest(['s' => 'sideways']), 's', AttendanceStatus::class))->toBeNull()
        ->and(ListFilter::enum(filterRequest(['s' => ['late']]), 's', AttendanceStatus::class))->toBeNull()
        ->and(ListFilter::enum(filterRequest([]), 's', AttendanceStatus::class))->toBeNull();
});

it('D-136: نمط LIKE يحيط النص بعلامتي النسبة', function (): void {
    expect(ListFilter::like('abc'))->toBe('%abc%');
});
