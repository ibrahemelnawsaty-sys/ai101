<?php

declare(strict_types=1);

/**
 * Every component the markup asks Alpine for is registered by the JavaScript.
 *
 * WHY THIS SUITE EXISTS
 * Nine components were being called and none of them existed. Each failure is
 * silent in the source and total in the browser: the expression throws, and
 * every binding in that subtree dies with it. Two of the nine were the platform
 * unable to do its job at all —
 *
 *   · `atharUploader` on the assignment screen: pressing "hand in" did nothing,
 *     because x-on:submit.prevent cancels the native submit BEFORE evaluating
 *     the handler that was going to replace it. Zero submissions, for anyone.
 *   · `atharUploader` again on the final project, which is half the marks.
 *   · `atharRoster` on the trainer's attendance screen.
 *
 * They were found one at a time, by a human opening a browser console, over
 * several rounds. Nothing in twelve gates and a full test suite could see them,
 * because both halves are individually valid: the Blade parses, the JavaScript
 * parses, and only the NAME between them disagrees.
 *
 * That is the same shape as D-40 (form verb vs route verb), D-45 (component
 * prop vs its declaration) and D-50 (copy placeholder vs the code that fills
 * it): a contract between two files that nothing was comparing.
 *
 * @see CONSTITUTION.md Article 21 · D-44, D-54, D-55
 */

use Illuminate\Support\Facades\File;

/** Every name `Alpine.data(...)` registers, plus the globals x-data can reach. */
function registeredAlpineComponents(): array
{
    $names = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if ($file->getExtension() !== 'js') {
            continue;
        }

        $source = (string) File::get($file->getPathname());

        preg_match_all('/Alpine\.data\(\s*[\'"]([\w-]+)[\'"]/', $source, $data);
        preg_match_all('/^(?:export\s+)?function\s+(\w+)\s*\(/m', $source, $fns);
        preg_match_all('/window\.(\w+)\s*=/', $source, $globals);

        $names = array_merge($names, $data[1], $fns[1], $globals[1]);
    }

    return array_values(array_unique($names));
}

/**
 * Every `x-data="name(...)"` in the view tree.
 *
 * @return list<array{name: string, where: string}>
 */
function requestedAlpineComponents(): array
{
    $found = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        // Blade comments in these files explain this very class of bug and name
        // the broken components while doing it. Prose is not markup.
        $source = (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) File::get($file->getPathname()),
        );

        if (preg_match_all('/x-data\s*=\s*"([^"]*)"/', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($matches[1] as $index => $match) {
            $expression = trim($match[0]);

            // An inline object literal, or a bare x-data, registers nothing.
            if ($expression === '' || str_starts_with($expression, '{')) {
                continue;
            }

            if (preg_match('/^([A-Za-z_$][\w$]*)\s*\(/', $expression, $call) !== 1) {
                continue;
            }

            $offset = (int) $matches[0][$index][1];

            $found[] = [
                'name' => $call[1],
                'where' => sprintf(
                    '%s:%d',
                    str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                    substr_count(substr($source, 0, $offset), "\n") + 1,
                ),
            ];
        }
    }

    return $found;
}

it('D-55: كل مكوّن يطلبه القالب مُسجَّل في JavaScript', function (): void {
    $registered = registeredAlpineComponents();

    $offenders = [];

    foreach (requestedAlpineComponents() as $request) {
        if (! in_array($request['name'], $registered, true)) {
            $offenders[] = sprintf(
                '%s — x-data calls %s(), which nothing registers',
                $request['where'],
                $request['name'],
            );
        }
    }

    // A RATCHET, not a clean sheet. Eleven call sites were broken when this was
    // written; seven are fixed and four remain, all of them enhancements on
    // screens that render and work without them:
    //
    //   passwordStrength · resendCooldown · atharThread · atharSchedule
    //
    // The number was MEASURED, not guessed. Lower it as each is written or
    // removed; never raise it. A new one fails the build, which is the whole
    // point — every one of these was found by a human opening a console.
    expect(count($offenders))->toBeLessThanOrEqual(4, implode(PHP_EOL, $offenders));
});

it('D-54: لا نموذج يُلغي إرساله الأصلي لصالح معالِج JavaScript', function (): void {
    // `x-on:submit.prevent` calls preventDefault() BEFORE evaluating the
    // expression. If the handler is missing — or simply throws — the form is
    // dead: the native submit was already cancelled and nothing replaced it.
    // On the assignment screen that meant no trainee could hand in any work.
    //
    // A form may still be enhanced by JavaScript; it may not depend on it.
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $source = (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) File::get($file->getPathname()),
        );

        if (preg_match_all('/<form\b.*?<\/form>/s', $source, $forms, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($forms[0] as $form) {
            if (! str_contains($form[0], 'submit.prevent')) {
                continue;
            }

            $offenders[] = sprintf(
                '%s:%d — a form cancels its own submit and hands the job to JavaScript',
                str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                substr_count(substr($source, 0, (int) $form[1]), "\n") + 1,
            );
        }
    }

    expect($offenders)->toBe([]);
});
