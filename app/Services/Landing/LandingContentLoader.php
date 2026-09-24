<?php

declare(strict_types=1);

namespace App\Services\Landing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Arr;

/**
 * The translation loader, with the landing group's published overrides laid
 * over the file.
 *
 * WHY HERE
 * The public pages already ask for every sentence through __('landing.*') and
 * trans_choice('landing.*'). Laying the overrides in at load time means every
 * one of those calls — in the templates, the controllers, the layout and the
 * footer — returns the centre's copy without a single call site changing, and
 * without a second way of printing text that could drift from the first
 * (Constitution, Article 6).
 *
 * WHAT IT WILL NOT DO
 * It only replaces a line the file already holds as a string. A stale row for
 * a key that was since removed from the file, or a row aimed at a nested
 * array, is ignored rather than injected: the file decides which texts exist,
 * the database only decides their words.
 *
 * Every other group and every namespaced group passes through untouched.
 *
 * @see BR-31, BR-36 · PRD §9.1 · D-114
 */
final class LandingContentLoader implements Loader
{
    public function __construct(
        private readonly Loader $inner,
        private readonly Container $container,
    ) {}

    /**
     * @param  string  $locale
     * @param  string  $group
     * @param  string|null  $namespace
     * @return array<array-key, mixed>
     */
    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->inner->load($locale, $group, $namespace);

        if ($group !== LandingCatalog::GROUP || ($namespace !== null && $namespace !== '*')) {
            return $lines;
        }

        $overrides = $this->container->make(LandingOverrides::class)->forLocale((string) $locale);

        foreach ($overrides as $name => $value) {
            if (is_string(Arr::get($lines, $name))) {
                Arr::set($lines, $name, $value);
            }
        }

        return $lines;
    }

    /**
     * @param  string  $namespace
     * @param  string  $hint
     */
    public function addNamespace($namespace, $hint): void
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    /**
     * @param  string  $path
     */
    public function addJsonPath($path): void
    {
        $this->inner->addJsonPath($path);
    }

    /**
     * @return array<string, string>
     */
    public function namespaces()
    {
        return $this->inner->namespaces();
    }
}
