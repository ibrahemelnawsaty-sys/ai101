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

/* ==========================================================================
   MEMBER-LEVEL CONTRACT (D-61)

   D-55 compared the component NAME and stopped there, and the card screen
   showed that is only half the contract. `atharCardTilt` was registered under
   exactly the right name and the card still died in the browser:

       Alpine Expression Error: atharCardTilt is not defined
       Alpine Expression Error: transformStyle is not defined

   because the markup calls `track($event)` and binds `transformStyle`, while
   the object written for it defined `move(event)` and `get style()`. The name
   matched; the members did not. Same failure shape, one level deeper — and
   just as total, because one throwing expression takes its whole subtree with
   it. On the card that was the tilt, the copy button and the share button.
   ========================================================================== */

/** The closing index of the bracket opened at $open, ignoring strings and comments. */
function jsMatchFrom(string $src, int $open): int
{
    $opener = $src[$open] ?? '';
    $closer = match ($opener) {
        '{' => '}',
        '(' => ')',
        '[' => ']',
        default => '',
    };

    if ($closer === '') {
        return -1;
    }

    $depth = 0;
    $quote = null;
    $length = strlen($src);

    for ($i = $open; $i < $length; $i++) {
        $c = $src[$i];

        if ($quote !== null) {
            if ($c === '\\') {
                $i++;

                continue;
            }

            if ($c === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($c === '"' || $c === "'" || $c === '`') {
            $quote = $c;

            continue;
        }

        if ($c === '/' && ($src[$i + 1] ?? '') === '/') {
            $newline = strpos($src, "\n", $i);

            if ($newline === false) {
                break;
            }

            $i = $newline;

            continue;
        }

        if ($c === '/' && ($src[$i + 1] ?? '') === '*') {
            $end = strpos($src, '*/', $i);

            if ($end === false) {
                break;
            }

            $i = $end + 1;

            continue;
        }

        if ($c === $opener) {
            $depth++;
        } elseif ($c === $closer) {
            $depth--;

            if ($depth === 0) {
                return $i;
            }
        }
    }

    return -1;
}

/**
 * The names declared at the top level of a JavaScript object literal.
 *
 * A spread is reported as `...`, and a component carrying one is skipped by the
 * caller: its real members are somewhere this cannot see, and guessing would
 * turn a useful check into a false alarm.
 *
 * @return list<string>
 */
function jsObjectMembers(string $object): array
{
    $inner = substr($object, 1, -1);
    $chunks = [];
    $start = 0;
    $depth = 0;
    $quote = null;
    $length = strlen($inner);

    for ($i = 0; $i < $length; $i++) {
        $c = $inner[$i];

        if ($quote !== null) {
            if ($c === '\\') {
                $i++;

                continue;
            }

            if ($c === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($c === '"' || $c === "'" || $c === '`') {
            $quote = $c;

            continue;
        }

        if ($c === '/' && ($inner[$i + 1] ?? '') === '/') {
            $newline = strpos($inner, "\n", $i);
            $i = $newline === false ? $length : $newline;

            continue;
        }

        if ($c === '/' && ($inner[$i + 1] ?? '') === '*') {
            $end = strpos($inner, '*/', $i);
            $i = $end === false ? $length : $end + 1;

            continue;
        }

        if ($c === '{' || $c === '(' || $c === '[') {
            $depth++;
        } elseif ($c === '}' || $c === ')' || $c === ']') {
            $depth--;
        } elseif ($c === ',' && $depth === 0) {
            $chunks[] = substr($inner, $start, $i - $start);
            $start = $i + 1;
        }
    }

    $chunks[] = substr($inner, $start);

    $names = [];

    foreach ($chunks as $chunk) {
        if (preg_match('/^\s*\.\.\./', $chunk) === 1) {
            $names[] = '...';

            continue;
        }

        // `name:`, `name(`, `get name`, and bare shorthand `name` — omitting the
        // last would report a real member as absent.
        $declaration = '/^\s*(?:\/\*[\s\S]*?\*\/\s*|\/\/[^\n]*\n\s*)*(?:(?:get|set|async)\s+)?([A-Za-z_$][\w$]*)\s*(?:[:(]|$)/';

        if (preg_match($declaration, $chunk, $match) === 1) {
            $names[] = $match[1];
        }
    }

    return array_values(array_unique($names));
}

/**
 * Every registered component and the members it actually exposes.
 *
 * @return array<string, list<string>>
 */
function alpineComponentMembers(): array
{
    $components = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if ($file->getExtension() !== 'js') {
            continue;
        }

        $source = (string) File::get($file->getPathname());

        $registration = '/Alpine\.data\(\s*[\'"]([\w-]+)[\'"]\s*,\s*([A-Za-z_$][\w$]*)\s*(?:\(\s*\))?\s*\)/';

        if (preg_match_all($registration, $source, $registrations, PREG_SET_ORDER) === 0) {
            continue;
        }

        foreach ($registrations as [, $name, $factory]) {
            $declaration = '/(?:^|\n)\s*(?:export\s+)?function\s+'.preg_quote($factory, '/').'\s*\(/';

            if (preg_match($declaration, $source, $where, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            // The first `{` after the keyword may be a DEFAULT PARAMETER value
            // — `function uiDialog(options = {})` — so step over the whole
            // parameter list first. A version of this that did not skipped ten
            // components in silence, which is exactly the failure it exists to
            // catch.
            $paramsOpen = strpos($source, '(', (int) $where[0][1]);

            if ($paramsOpen === false) {
                continue;
            }

            $paramsEnd = jsMatchFrom($source, $paramsOpen);

            if ($paramsEnd < 0) {
                continue;
            }

            $bodyOpen = strpos($source, '{', $paramsEnd + 1);

            if ($bodyOpen === false) {
                continue;
            }

            $bodyEnd = jsMatchFrom($source, $bodyOpen);

            if ($bodyEnd < 0) {
                continue;
            }

            $body = substr($source, $bodyOpen, $bodyEnd - $bodyOpen + 1);

            // Either `return { … }` or `return (config = {}) => ({ … })`.
            $objectOpen = false;

            if (preg_match('/=>\s*\(\s*\{/', $body, $arrow, PREG_OFFSET_CAPTURE) === 1) {
                $paren = strpos($body, '(', (int) $arrow[0][1] + 2);
                $objectOpen = $paren === false ? false : strpos($body, '{', $paren);
            } elseif (preg_match('/\breturn\s*\{/', $body, $plain, PREG_OFFSET_CAPTURE) === 1) {
                $objectOpen = strpos($body, '{', (int) $plain[0][1]);
            }

            if ($objectOpen === false) {
                continue;
            }

            $objectEnd = jsMatchFrom($body, $objectOpen);

            if ($objectEnd < 0) {
                continue;
            }

            $components[$name] = jsObjectMembers(substr($body, $objectOpen, $objectEnd - $objectOpen + 1));
        }
    }

    return $components;
}

/** Blade directives are gone before the browser ever sees the attribute. */
function withoutBladeDirectives(string $expression): string
{
    while (preg_match('/@[A-Za-z]\w*\s*\(/', $expression, $match, PREG_OFFSET_CAPTURE) === 1) {
        $at = (int) $match[0][1];
        $open = strpos($expression, '(', $at);
        $end = $open === false ? -1 : jsMatchFrom($expression, $open);

        if ($end < 0) {
            return substr($expression, 0, $at).' ';
        }

        $expression = substr($expression, 0, $at).' '.substr($expression, $end + 1);
    }

    return $expression;
}

it('D-61: كل عضو يطلبه القالب موجود فعلًا على المكوّن المسجَّل', function (): void {
    $components = alpineComponentMembers();

    // Names the browser resolves without help from the component: JavaScript
    // keywords, literals, and the globals an expression may legitimately reach
    // for. Alpine's own magics all begin with `$` and are filtered by shape.
    $ambient = [
        'true', 'false', 'null', 'undefined', 'this', 'typeof', 'new', 'in',
        'of', 'return', 'if', 'else', 'await', 'async', 'function', 'let',
        'const', 'var', 'window', 'document', 'navigator', 'Math', 'JSON',
        'Date', 'Number', 'String', 'Boolean', 'Array', 'Object', 'setTimeout',
        'clearTimeout', 'location', 'history', 'localStorage', 'sessionStorage',
    ];

    $expressionAttribute = '/\bx-(on:[\w.:-]+|bind:[\w.:-]+|text|html|show|model(?:\.[\w.]+)?|if|init|effect)\s*=\s*"([^"]*)"/';

    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        // Blade comments are stripped, but each is replaced by as many blank
        // characters as it occupied: remove them outright and every line number
        // this test reports is wrong by the length of the prose above it.
        $source = (string) preg_replace_callback(
            '/\{\{--[\s\S]*?--\}\}/',
            static fn (array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]),
            (string) File::get($file->getPathname()),
        );

        // A bare `x-data` opens a fresh, empty scope. It registers nothing, but
        // it ENDS the previous component's region.
        if (preg_match_all('/x-data(?:\s*=\s*"([^"]*)")?/', $source, $scopes, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        $total = count($scopes[0]);

        for ($index = 0; $index < $total; $index++) {
            $expression = trim((string) $scopes[1][$index][0]);

            if (preg_match('/^([A-Za-z_$][\w$]*)\s*\(/', $expression, $call) !== 1) {
                continue;
            }

            $component = $call[1];

            if (! array_key_exists($component, $components) || in_array('...', $components[$component], true)) {
                continue;
            }

            $from = (int) $scopes[0][$index][1];
            $to = $index + 1 < $total ? (int) $scopes[0][$index + 1][1] : strlen($source);
            $region = substr($source, $from, $to - $from);

            $scope = array_merge($components[$component], $ambient);

            // `x-for` puts its own names into scope for everything beneath it.
            if (preg_match_all('/x-for\s*=\s*"\s*\(?([^)"]*?)\)?\s+(?:in|of)\s/', $region, $loops) > 0) {
                foreach ($loops[1] as $declared) {
                    foreach (explode(',', $declared) as $bound) {
                        $scope[] = trim($bound);
                    }
                }
            }

            if (preg_match_all($expressionAttribute, $region, $attributes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($attributes as $attribute) {
                $cleaned = withoutBladeDirectives((string) $attribute[2][0]);
                $cleaned = (string) preg_replace(
                    ['/\{\{[\s\S]*?\}\}/', '/\'[^\']*\'/', '/`[^`]*`/'],
                    ' ',
                    $cleaned,
                );

                preg_match_all('/(?<![\w$.])([A-Za-z_$][\w$]*)/', $cleaned, $identifiers);

                foreach ($identifiers[1] as $identifier) {
                    if (str_starts_with($identifier, '$') || in_array($identifier, $scope, true)) {
                        continue;
                    }

                    $offenders[] = sprintf(
                        '%s:%d — %s has no "%s"  (x-%s)',
                        str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                        substr_count(substr($source, 0, $from + (int) $attribute[0][1]), "\n") + 1,
                        $component,
                        $identifier,
                        (string) $attribute[1][0],
                    );
                }
            }
        }
    }

    // Not a ratchet. The tree measured clean when this was written, and the two
    // it would have caught — `track` and `transformStyle` on the card — are
    // fixed. A new entry here is a screen that has stopped working.
    expect(array_values(array_unique($offenders)))->toBe([]);
});

it('D-64: لا عنصر يحمل x-show و x-bind:style معًا', function (): void {
    // They fight over ONE attribute. `x-show` hides an element by writing
    // `display: none` into `style`; Alpine's string form of `x-bind:style`
    // calls `setAttribute('style', …)`, which replaces the whole attribute and
    // wipes that `display: none` a moment after it was written.
    //
    // The element then renders VISIBLE. On the select component that meant
    // every dropdown on every screen of the platform was open on page load,
    // before anyone clicked anything — and the owner saw it on the first page
    // they opened. Both directives are individually correct; only their
    // combination is wrong, which is why nothing caught it.
    //
    // Position an element from JavaScript with `style.setProperty()`, which
    // mutates one declaration and leaves the rest alone.
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $source = (string) preg_replace_callback(
            '/\{\{--[\s\S]*?--\}\}/',
            static fn (array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]),
            (string) File::get($file->getPathname()),
        );

        // Each opening tag, whole, so the two attributes are only compared
        // when they sit on the SAME element.
        if (preg_match_all('/<[a-zA-Z][^>]*>/s', $source, $tags, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($tags[0] as $tag) {
            $markup = (string) $tag[0];

            if (! str_contains($markup, 'x-show') || ! preg_match('/x-bind:style|(?<![\w:-]):style\s*=/', $markup)) {
                continue;
            }

            $offenders[] = sprintf(
                '%s:%d — x-show and a bound style on one element; the binding wipes display:none',
                str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                substr_count(substr($source, 0, (int) $tag[1]), "\n") + 1,
            );
        }
    }

    expect($offenders)->toBe([]);
});
