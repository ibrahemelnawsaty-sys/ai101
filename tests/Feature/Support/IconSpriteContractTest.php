<?php

declare(strict_types=1);

/**
 * The icon system as a contract (D-127, PROJECT-CONTRACT §17).
 *
 * Before the overhaul the platform had two hand-kept sprites that had drifted
 * (the public one lacked seventeen ids the app one had, and drew `i-home` and
 * `i-x` differently), six different stroke weights, a rail whose "dashboard"
 * and "collapse" items shared one glyph, a settings item drawn as a padlock,
 * and ids that were referenced and never defined — which render an empty
 * square and throw nothing. Each of those is now a failing test here, so a
 * regression is caught by the suite and not by someone squinting at a rail.
 *
 * @see D-127 · PROJECT-CONTRACT §17 · PRD §5.7 · CONSTITUTION art. 6, 16, 18
 */

use App\Enums\SessionPlatform;
use App\Support\Icons;
use App\View\Components\Layout\Sidebar;

/** The filled marks that are not part of the Lucide outline family. */
const FILLED_MARKS = ['i-wa', 'i-zoom', 'i-meet', 'i-teams'];

/** @return array<string, string> id => the symbol's opening tag + body */
function spriteSymbols(): array
{
    $source = (string) file_get_contents(resource_path('views/partials/icon-sprite.blade.php'));
    preg_match_all('#<symbol\s+id="([^"]+)"([^>]*)>(.*?)</symbol>#s', $source, $matches, PREG_SET_ORDER);

    $symbols = [];

    foreach ($matches as $match) {
        $symbols[$match[1]] = $match[2].'>'.$match[3];
    }

    return $symbols;
}

function spriteHas(string $icon): bool
{
    $id = str_starts_with($icon, 'i-') ? $icon : 'i-'.$icon;

    return array_key_exists($id, spriteSymbols());
}

it('D-127: there is exactly one sprite file, and every layout includes it', function (): void {
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && str_contains((string) file_get_contents($file->getPathname()), '<symbol ')) {
            $files[] = str_replace(resource_path('views').'/', '', $file->getPathname());
        }
    }

    // The mail templates draw no icons; nothing else may carry a <symbol>.
    expect($files)->toBe(['partials/icon-sprite.blade.php']);

    foreach (['app', 'auth', 'bare', 'public'] as $layout) {
        expect((string) file_get_contents(resource_path("views/layouts/{$layout}.blade.php")))
            ->toContain("@include('partials.icon-sprite')");
    }
});

it('D-127: every symbol is prefixed, unique and on the 24-unit grid', function (): void {
    $source = (string) file_get_contents(resource_path('views/partials/icon-sprite.blade.php'));
    preg_match_all('#<symbol\s+id="([^"]+)"#', $source, $ids);

    expect($ids[1])->not->toBeEmpty()
        ->and($ids[1])->toBe(array_values(array_unique($ids[1])), 'a duplicated id is a second drawing nobody can reach');

    foreach (spriteSymbols() as $id => $markup) {
        expect($id)->toMatch('/^i-[a-z0-9]+(-[a-z0-9]+)*$/')
            ->and($markup)->toContain('viewBox="0 0 24 24"');
    }
});

it('D-127: no outline symbol carries a stroke width of its own — the token is the one weight', function (): void {
    foreach (spriteSymbols() as $id => $markup) {
        if (in_array($id, FILLED_MARKS, true)) {
            expect($markup)->toContain('fill="currentColor"');

            continue;
        }

        expect($markup)->toContain('fill="none"')
            ->and($markup)->toContain('stroke="currentColor"')
            ->and($markup)->not->toContain('stroke-width'); // {$id}: one edit of --bw-glyph must move every icon
    }

    $tokens = (string) file_get_contents(resource_path('css/tokens.css'));
    $components = (string) file_get_contents(resource_path('css/components.css'));

    expect($tokens)->toContain('--bw-glyph:')
        ->and($components)->toContain('use { stroke-width: var(--bw-glyph); }');
});

it('D-127: every concept in App\\Support\\Icons names a drawn symbol, and no two concepts share one', function (): void {
    $all = Icons::all();

    $undrawn = [];

    foreach ($all as $constant => $id) {
        if (! spriteHas($id)) {
            $undrawn[] = "Icons::{$constant} names '{$id}', which the sprite does not draw";
        }
    }

    expect($undrawn)->toBe([]);

    $duplicates = array_keys(array_filter(array_count_values($all), static fn (int $n): bool => $n > 1));

    expect($duplicates)->toBe([], 'two concepts drawn as one glyph: '.implode(', ', $duplicates));
});

it('D-127: every static icon reference in a view or presenter resolves to a symbol', function (): void {
    $missing = [];

    $check = static function (string $icon, string $where) use (&$missing): void {
        if ($icon === '' || str_contains($icon, '$') || str_contains($icon, '{')) {
            return;
        }

        if (! spriteHas($icon)) {
            $missing[] = "{$icon} ({$where})";
        }
    };

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $relative = str_replace(resource_path('views').'/', '', $file->getPathname());
        $source = (string) file_get_contents($file->getPathname());

        // <use href="#i-x"> · icon="x" · icon-end="x" · <x-ui.icon name="x">  (static values only)
        preg_match_all('/href="#(i-[a-z0-9-]+)"/', $source, $uses);
        preg_match_all('/(?<![:\w-])(?:icon|icon-end|iconEnd)="([a-z0-9-]+)"/', $source, $props);
        preg_match_all('/<x-ui\.icon\s+name="([a-z0-9-]+)"/', $source, $names);

        foreach ([...$uses[1], ...$props[1], ...$names[1]] as $icon) {
            $check($icon, $relative);
        }
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(app_path().'/', 'app/', $file->getPathname());
        preg_match_all("/'(?:icon|statusIcon)'\s*=>\s*'([a-z0-9-]+)'/", (string) file_get_contents($file->getPathname()), $found);

        foreach ($found[1] as $icon) {
            $check($icon, $relative);
        }
    }

    foreach (SessionPlatform::cases() as $platform) {
        $check((string) $platform->icon(), 'SessionPlatform::'.$platform->name);
    }

    expect($missing)->toBe([], "referenced and never drawn — each renders an empty square:\n".implode("\n", $missing));
});

it('D-127: no rail draws two of its items with the same glyph, and none draws Settings as a padlock', function (): void {
    $sidebar = new Sidebar;
    $undrawn = [];

    foreach (['participantGroups', 'trainerGroups', 'coordinatorGroups', 'adminGroups', 'systemAdminGroups'] as $method) {
        $reflection = new ReflectionMethod(Sidebar::class, $method);
        $reflection->setAccessible(true);

        $icons = [];

        foreach ($reflection->invoke($sidebar) as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $icons[] = (string) ($item['icon'] ?? '');

                if (! spriteHas((string) $item['icon'])) {
                    $undrawn[] = "{$method}: '{$item['icon']}' is not drawn";
                }
            }
        }

        $repeated = array_keys(array_filter(array_count_values($icons), static fn (int $n): bool => $n > 1));

        expect($repeated)->toBe([], "{$method} reuses a glyph for two items: ".implode(', ', $repeated));
    }

    expect($undrawn)->toBe([])
        ->and(Icons::SETTINGS)->not->toBe(Icons::LOCKED)
        ->and(Icons::DASHBOARD)->not->toBe(Icons::SIDEBAR_COLLAPSE)
        ->and(Icons::ERROR)->not->toBe(Icons::WARNING);
});
