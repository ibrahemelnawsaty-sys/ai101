<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Support\ViewModel;

/**
 * One e-mail template, listed for review.
 *
 * Template bodies live in lang/{ar,en}/emails.php like every other string on the
 * platform (art. 15), so this screen shows what will be sent; changing it is a
 * translation change, not a form submission. The list is therefore built from
 * that file and stays in step with it by construction.
 *
 * @see BR-36 · PRD §9.18, §12.5 · CONSTITUTION art. 15
 */
final class EmailTemplateRow extends ViewModel
{
    /** The `common` group is shared wording, not a template of its own. */
    private const NOT_A_TEMPLATE = ['common'];

    /**
     * @param  array<string, mixed>  $template
     */
    public static function of(string $key, array $template): self
    {
        $subject = is_string($template['subject'] ?? null) ? $template['subject'] : '';
        $heading = is_string($template['heading'] ?? null) ? $template['heading'] : '';

        return new self([
            'key' => $key,
            'label' => $heading !== '' ? $heading : ($subject !== '' ? $subject : $key),
            'subject' => $subject,
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function all(): \Illuminate\Support\Collection
    {
        $templates = __('emails');

        if (! is_array($templates)) {
            return collect();
        }

        $rows = [];

        foreach ($templates as $key => $template) {
            if (! is_string($key) || ! is_array($template) || in_array($key, self::NOT_A_TEMPLATE, true)) {
                continue;
            }

            $rows[] = self::of($key, $template);
        }

        return collect($rows);
    }
}
