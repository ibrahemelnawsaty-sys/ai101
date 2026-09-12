<?php

declare(strict_types=1);

/**
 * Every event has somebody listening and somebody dispatching.
 *
 * WHY THIS SUITE EXISTS
 * Two ways an event does nothing, both shipped here. Four events were
 * dispatched into a room with no listener in it (D-49). Then five letters the
 * PRD requires had a listener, copy in two languages, and not one dispatch site
 * in the application (D-77). Each half looked complete on its own.
 *
 * Dispatch sites are found by tokens, not text: comments here quote
 * `EnrollmentApproved::dispatch()` and `EmailTokenIssued::dispatch(...)` to
 * explain why they are NOT called, and a text search would count them.
 *
 * @see D-49, D-51, D-77
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

/** @return list<string> every class in app/Events */
function eventClasses(): array
{
    return array_values(array_map(
        static fn (string $file): string => 'App\\Events\\'.basename($file, '.php'),
        glob(app_path('Events/*.php')) ?: [],
    ));
}

it('D-49: كل حدث في app/Events له مستمع مكتشَف', function (): void {
    $raw = Event::getRawListeners();

    $orphans = array_values(array_filter(
        eventClasses(),
        static fn (string $class): bool => ($raw[$class] ?? []) === [],
    ));

    expect($orphans)->toBe([]);
});

it('D-77: كل حدث في app/Events يُطلَق من موضع حقيقي في الشيفرة لا من تعليق', function (): void {
    $dispatched = [];

    foreach (File::allFiles(app_path()) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if (str_contains($path, '/app/Events/') || str_contains($path, '/app/Listeners/')) {
            continue;
        }

        $tokens = array_values(array_filter(
            PhpToken::tokenize((string) File::get($file->getPathname())),
            static fn (PhpToken $t): bool => ! $t->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE]),
        ));

        foreach ($tokens as $i => $token) {
            // Foo::dispatch( · Foo::dispatchIf( · Foo::dispatchUnless(
            if ($token->is(T_STRING) && ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)
                && in_array($tokens[$i + 2]->text ?? '', ['dispatch', 'dispatchIf', 'dispatchUnless'], true)) {
                $dispatched[$token->text] = true;
            }

            // event(new Foo(
            if ($token->text === 'event' && ($tokens[$i + 1]->text ?? '') === '('
                && ($tokens[$i + 2] ?? null)?->is(T_NEW)) {
                $dispatched[$tokens[$i + 3]->text ?? ''] = true;
            }
        }
    }

    $silent = array_values(array_filter(
        eventClasses(),
        static fn (string $class): bool => ! isset($dispatched[class_basename($class)]),
    ));

    expect($silent)->toBe([]);
});
