<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Services\Mail\EmailOverrides;
use App\Services\Mail\EmailTemplates;
use App\Support\ViewModel;

/**
 * One e-mail template, listed for editing (BR-31, D-136).
 *
 * The words of a letter live in lang/{ar,en}/emails.php like every other string
 * on the platform (art. 15); the SUBJECT and the BODY of each can be reworded
 * from the editor, the published wording laid over the file's. The list is built
 * from that file and stays in step with it by construction — and lists only the
 * templates that have something to edit (EmailTemplates), each marked when its
 * wording has been changed.
 *
 * @see BR-31, BR-36 · PRD §9.18, §12.5 · CONSTITUTION art. 15 · D-114, D-136
 */
final class EmailTemplateRow extends ViewModel
{
    /**
     * @param  array<string, mixed>  $template
     */
    public static function of(string $key, array $template, bool $isCustomised = false): self
    {
        $subject = is_string($template['subject'] ?? null) ? $template['subject'] : '';

        return new self([
            'key' => $key,
            'label' => self::labelOf($key, $template),
            'subject' => $subject,
            'isCustomised' => $isCustomised,
        ]);
    }

    /**
     * The name a template is listed under: its heading, else its subject.
     *
     * @param  array<string, mixed>  $template
     */
    public static function labelOf(string $key, array $template): string
    {
        $subject = is_string($template['subject'] ?? null) ? $template['subject'] : '';
        $heading = is_string($template['heading'] ?? null) ? $template['heading'] : '';

        return $heading !== '' ? $heading : ($subject !== '' ? $subject : $key);
    }

    /**
     * Whether any of the template's texts carries a published override.
     *
     * @param  array<string, array{ar: string|null, en: string|null}>  $published
     */
    public static function isCustomised(string $template, array $published): bool
    {
        $prefix = EmailTemplates::GROUP.'.'.$template.'.';

        foreach ($published as $key => $values) {
            if (str_starts_with($key, $prefix) && ($values['ar'] !== null || $values['en'] !== null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The templates that have something to edit, in the file's order.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function all(): \Illuminate\Support\Collection
    {
        $templates = app(EmailTemplates::class);
        $published = app(EmailOverrides::class)->published();
        $rows = [];

        foreach ($templates->keys() as $key) {
            $copy = __(EmailTemplates::GROUP.'.'.$key);

            if (is_array($copy)) {
                $rows[] = self::of($key, $copy, self::isCustomised($key, $published));
            }
        }

        return collect($rows);
    }
}
