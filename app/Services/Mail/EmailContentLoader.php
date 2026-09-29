<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Arr;

/**
 * The translation loader, with the e-mail group's published overrides laid over
 * the file.
 *
 * WHY HERE
 * Every letter already asks for its words through __('emails.*'). Laying the
 * overrides in at load time means every one of those calls — in the Mailables,
 * the layouts, the queued jobs that render later — returns the centre's wording
 * without a single call site changing, and without a second way of printing
 * text that could drift from the first (Constitution, Article 6).
 *
 * WHAT IT WILL NOT DO
 * It only replaces a line that the file holds as a string AND that the editor
 * may write: a template's subject or body (EmailTemplates). A stale row for a
 * key that was since removed from the file, a row aimed at a heading, a button
 * or a security note, or one aimed at a nested array, is ignored rather than
 * injected: the file decides which texts exist and which are editable, the
 * database only decides the words of those.
 *
 * Every other group and every namespaced group passes through untouched.
 *
 * @see BR-31, BR-36 · PRD §9.16 · D-114, D-136
 */
final class EmailContentLoader implements Loader
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

        if ($group !== EmailTemplates::GROUP || ($namespace !== null && $namespace !== '*')) {
            return $lines;
        }

        $overrides = $this->container->make(EmailOverrides::class)->forLocale((string) $locale);

        if ($overrides === []) {
            return $lines;
        }

        $templates = $this->container->make(EmailTemplates::class);

        foreach ($overrides as $name => $value) {
            [$template, $field] = array_pad(explode('.', $name, 2), 2, '');

            if (in_array($field, $templates->fieldsOf($template), true) && is_string(Arr::get($lines, $name))) {
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
