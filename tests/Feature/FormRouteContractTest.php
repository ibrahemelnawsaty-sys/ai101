<?php

declare(strict_types=1);

/**
 * Every form must send the HTTP verb its route registers.
 *
 * WHY THIS SUITE EXISTS
 * Eleven forms on the live platform sent a verb their route did not accept.
 * Pressing "approve this registration", "cancel this session", "save settings",
 * "change this user's role" or "mark all read" produced a bare 405 — the
 * platform could not write at all. It shipped that way because nothing in the
 * suite renders a form and follows its action: the feature tests call
 * `$this->put(route(...))` directly, so the route is exercised and the button
 * that is supposed to reach it never is.
 *
 * A form is a contract with a route, and until now nothing checked it. This
 * test walks every `<form>` in every template, resolves the route its action
 * names, and compares the verb. It is the cheapest test in the suite and it
 * would have caught all eleven before they left the machine.
 *
 * It deliberately reads the TEMPLATE SOURCE rather than rendering pages: a
 * rendered page only proves the forms on that one screen, and half of these
 * live behind data the test database does not have.
 *
 * @see CONSTITUTION.md Article 5, Article 21 · D-40
 */

use Illuminate\Support\Facades\Route;

/**
 * Every <form> in the view tree, with the verb it sends and the route it names.
 *
 * @return list<array{file: string, line: int, route: string, verb: string}>
 */
function formRouteContracts(): array
{
    $found = [];

    /** @var SplFileInfo $file */
    foreach (Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match_all('/<form\b.*?<\/form>/s', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($matches[0] as $match) {
            [$block, $offset] = $match;

            // Only forms whose action is a named route can be checked; an action
            // built some other way is out of scope rather than a failure.
            if (preg_match("/route\('([A-Za-z0-9_.\-]+)'/", $block, $route) !== 1) {
                continue;
            }

            // Laravel forms declare method="POST" and spoof the real verb with
            // @method(). The spoof wins whenever it is present.
            $verb = 'GET';

            if (preg_match('/method\s*=\s*["\']([A-Za-z]+)["\']/', $block, $html) === 1) {
                $verb = strtoupper($html[1]);
            }

            if (preg_match("/@method\('([A-Z]+)'\)/", $block, $spoof) === 1) {
                $verb = $spoof[1];
            }

            $found[] = [
                'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'route' => $route[1],
                'verb' => $verb,
            ];
        }
    }

    return $found;
}

it('D-40: كل نموذج يُرسل الفعل الذي يسجّله مساره', function (): void {
    $offenders = [];

    foreach (formRouteContracts() as $form) {
        $route = Route::getRoutes()->getByName($form['route']);

        if ($route === null) {
            $offenders[] = sprintf(
                '%s:%d — route(%s) is not registered at all',
                $form['file'],
                $form['line'],
                $form['route'],
            );

            continue;
        }

        $accepts = array_values(array_diff($route->methods(), ['HEAD']));

        if (! in_array($form['verb'], $accepts, true)) {
            // A mismatch is a 405 the first time a human presses the button.
            $offenders[] = sprintf(
                '%s:%d — form sends %s to %s which accepts %s',
                $form['file'],
                $form['line'],
                $form['verb'],
                $form['route'],
                implode('/', $accepts),
            );
        }
    }

    expect($offenders)->toBe([]);
});

it('D-40: لا نموذج يُرسل إلى مسار للقراءة فقط', function (): void {
    // The password-reset form posted at the route that RENDERS it rather than
    // the one that sends the link, so the endpoint that does the work had no
    // caller anywhere in the codebase. A GET-only target is always this mistake.
    $offenders = [];

    foreach (formRouteContracts() as $form) {
        if ($form['verb'] === 'GET') {
            continue;
        }

        $route = Route::getRoutes()->getByName($form['route']);

        if ($route === null) {
            continue;
        }

        $accepts = array_values(array_diff($route->methods(), ['HEAD']));

        if ($accepts === ['GET']) {
            $offenders[] = sprintf('%s:%d — %s is GET-only', $form['file'], $form['line'], $form['route']);
        }
    }

    expect($offenders)->toBe([]);
});
