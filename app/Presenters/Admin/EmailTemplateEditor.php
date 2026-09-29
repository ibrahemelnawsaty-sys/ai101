<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Services\Mail\EmailOverrides;
use App\Services\Mail\EmailPreview;
use App\Services\Mail\EmailTemplates;
use App\Support\ViewModel;

/**
 * One e-mail template as its editor page shows it (BR-31, D-136).
 *
 * The page needs, per editable field: what is published now, the file's
 * original, and the live values that field must keep; and, for the template as
 * a whole, the values it supplies (each with a sample) and the parts of the
 * letter that never change. All of it is decided here, from the rules
 * (EmailTemplates), so the template draws and decides nothing (art. 13).
 *
 * @see BR-31 · PRD §9.16, §9.18 · CONSTITUTION art. 5, art. 6, art. 13 · D-114, D-136
 */
final class EmailTemplateEditor extends ViewModel
{
    /** The parts of a letter the editor never touches, in the order it lists them. */
    private const FIXED = ['heading', 'cta', 'expiry_note', 'ignore_note', 'not_you', 'next_steps'];

    public static function of(string $template, EmailTemplates $templates, EmailPreview $preview, EmailOverrides $overrides): self
    {
        $published = $overrides->published();
        $samples = $preview->samples($template);
        $fields = [];

        foreach ($templates->fieldsOf($template) as $field) {
            $key = EmailTemplates::GROUP.'.'.$template.'.'.$field;

            // The values THIS field carries — the only ones it may use, and
            // all of them must stay (EmailTemplates::problemWith).
            $variables = [];

            foreach ($templates->requiredIn($template, $field) as $name) {
                $variables[] = ['name' => ':'.$name, 'sample' => $samples[$name] ?? ''];
            }

            $fields[] = [
                'name' => $field,
                'label' => (string) __('admin.email_editor.fields.'.$field),
                'value' => $published[$key]['ar'] ?? $templates->defaultOf($template, $field),
                'original' => $templates->defaultOf($template, $field),
                'variables' => $variables,
                'rows' => $field === 'body' ? 6 : 2,
                'max' => EmailTemplates::MAX_LENGTH[$field],
            ];
        }

        $fixed = [];
        $file = __(EmailTemplates::GROUP.'.'.$template);

        foreach (self::FIXED as $part) {
            $text = is_array($file) ? ($file[$part] ?? null) : null;

            if (is_string($text) && $text !== '') {
                $fixed[] = ['label' => (string) __('admin.email_editor.fixed.'.$part), 'text' => $text];
            }
        }

        return new self([
            'key' => $template,
            'label' => EmailTemplateRow::labelOf($template, is_array($file) ? $file : []),
            'fields' => $fields,
            'fixed' => $fixed,
            'isCustomised' => EmailTemplateRow::isCustomised($template, $published),
        ]);
    }
}
