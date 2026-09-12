<?php

declare(strict_types=1);

/**
 * Every property a template reads is published by some presenter.
 *
 * WHY THIS SUITE EXISTS
 * `ViewModel::__get` throws OutOfBoundsException on an unknown key, and Blade's
 * `old('x', $presenter->y)` evaluates its default EAGERLY — so the throw happens
 * before the page renders a single byte. There is no half-rendered screen and no
 * missing value: there is a 500.
 *
 * The trainer's session editor did exactly that on every open. The template read
 * `$editing->join_opens_minutes` while every key on `SessionForm` is camelCase
 * and that one was never published at all. `GET /trainer/sessions?edit=new` and
 * `?edit={id}` both 500'd, so no trainer could create a session, edit one, or
 * attach a meeting link — which is the whole of running the programme. Nothing
 * caught it: the presenter is valid PHP, the template is valid Blade, and only
 * the NAME between them disagrees.
 *
 * Same shape as D-40, D-45, D-50, D-54, D-55, D-61 and D-62.
 *
 * DELIBERATELY LOOSE IN ONE DIRECTION. A property that SOME presenter publishes
 * is accepted even where this view is handed a different one — mapping each
 * variable to its presenter would need the controller, and the looser check
 * already catches the failure that actually happens: a name nothing anywhere
 * publishes. Tightening it later can only find more.
 *
 * @see CONSTITUTION.md Article 21 · D-65
 */

use Illuminate\Support\Facades\File;

/**
 * Every property name reachable through `->` on a presenter or a model.
 *
 * @return array<string, true>
 */
function publishedProperties(): array
{
    $names = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) File::get($file->getPathname());

        // Presenters only. Scanning all of app/ sweeps in every `'key' =>` in
        // the application — validation rules, config arrays, audit payloads —
        // and the accepted set grows until it accepts everything. A check that
        // cannot fail is worse than no check: it reports green over a broken
        // screen.
        $isPresenter = str_contains($source, 'extends ViewModel')
            || str_contains($file->getPathname(), 'Presenters');
        $hasReadonly = str_contains($source, 'public readonly ');

        if (! $isPresenter && ! $hasReadonly) {
            continue;
        }

        // 'key' => … in a payload array.
        preg_match_all("/^\s*'([a-zA-Z_][\w]*)'\s*=>/m", $source, $keys);

        // A computed accessor: getFoo() is reached as ->foo.
        preg_match_all('/public function get([A-Z]\w*)\s*\(/', $source, $accessors);

        // A promoted readonly property is read directly.
        preg_match_all('/public readonly [\w|?\\\\]+ \$(\w+)/', $source, $promoted);

        foreach ($keys[1] as $name) {
            $names[$name] = true;
        }

        foreach ($accessors[1] as $name) {
            $names[lcfirst($name)] = true;
        }

        foreach ($promoted[1] as $name) {
            $names[$name] = true;
        }
    }

    // Eloquent relations are read through the same arrow.
    foreach (File::allFiles(app_path('Models')) as $file) {
        $source = (string) File::get($file->getPathname());

        preg_match_all(
            '/public function (\w+)\s*\(\s*\)\s*:\s*(?:HasOne|HasMany|BelongsTo|BelongsToMany|MorphMany|MorphTo)/',
            $source,
            $relations,
        );

        foreach ($relations[1] as $name) {
            $names[$name] = true;
        }
    }

    return $names;
}

it('D-65: كل خاصّية يقرؤها قالب ينشرها مُقدِّم', function (): void {
    $published = publishedProperties();

    // Variables Blade and the framework own.
    $skipVariable = [
        'this', 'attributes', 'slot', 'loop', 'errors', 'page', 'message',
        'component', 'request', 'validator', 'exception', 'e', 'app',
    ];

    // Names that belong to framework objects a template legitimately reaches
    // through — a paginator, a model's own column, a request.
    $ambient = [
        'id', 'name', 'value', 'label', 'title', 'url', 'links', 'items',
        'data', 'first', 'last', 'count', 'total', 'current_page', 'per_page',
        'path', 'email', 'status', 'role', 'locale', 'timezone',
        'created_at', 'updated_at',
    ];

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

        if (preg_match_all('/\$([a-zA-Z_]\w*)->([a-zA-Z_]\w*)/', $source, $reads, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($reads as $read) {
            [$whole, $variable, $property] = $read;

            // A method call, not a property. The lookahead must NOT live inside
            // the pattern: `\w*` backtracks to satisfy it, so `->isEmpty()`
            // matches as `isEmpt` and every method call in the tree is reported
            // as a missing property.
            if (preg_match('/^\s*\(/', substr($source, (int) $whole[1] + strlen((string) $whole[0]), 4)) === 1) {
                continue;
            }

            if (in_array((string) $variable[0], $skipVariable, true)) {
                continue;
            }

            if (in_array((string) $property[0], $ambient, true)) {
                continue;
            }

            if (array_key_exists((string) $property[0], $published)) {
                continue;
            }

            $offenders[] = sprintf(
                '%s:%d — $%s->%s is read and nothing publishes it',
                str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                substr_count(substr($source, 0, (int) $whole[1]), "\n") + 1,
                (string) $variable[0],
                (string) $property[0],
            );
        }
    }

    // Not a ratchet. The tree measured clean once `SessionForm` published the
    // key its editor had always read.
    expect(array_values(array_unique($offenders)))->toBe([]);
});
