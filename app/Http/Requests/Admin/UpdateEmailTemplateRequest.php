<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Mail\EmailTemplates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Saving an e-mail template's subject and body (BR-31, D-136).
 *
 * The rules are the server's (art. 5); the editor mirrors the same messages
 * while the person types, and decides nothing. A field the template does not
 * have — and every field that is not the subject or the body — is not in
 * `rules()`, so it is not in `validated()` and no form can write it (art. 22).
 *
 * Empty, or equal to the original, is valid and means "follow the file".
 * Anything else must keep every live value its original carries and add none
 * the letter does not supply (EmailTemplates::problemWith).
 *
 * Whether the person may is asked FIRST, so an outsider is refused 403 whatever
 * template they name and can never learn which templates exist.
 *
 * @see BR-31, BR-33 · PRD §9.16, §9.18 · CONSTITUTION art. 5, art. 22 · D-114, D-117, D-136
 */
final class UpdateEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('console.settings')) {
            return false;
        }

        // Past the role check a template that does not exist is simply not found.
        if (! in_array($this->template(), app(EmailTemplates::class)->keys(), true)) {
            throw new NotFoundHttpException;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (app(EmailTemplates::class)->fieldsOf($this->template()) as $field) {
            $rules[$field] = ['nullable', 'string'];
        }

        return $rules;
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $templates = app(EmailTemplates::class);

                foreach ($templates->fieldsOf($this->template()) as $field) {
                    $raw = $this->input($field);

                    // `string` already refused an array: there is no text to judge.
                    if (! is_string($raw)) {
                        continue;
                    }

                    $text = trim($raw);

                    // Empty and equal-to-original are "follow the file": nothing to check.
                    if (! $templates->isOverride($this->template(), $field, $text)) {
                        continue;
                    }

                    $problem = $templates->problemWith($this->template(), $field, $text);

                    if ($problem !== null) {
                        $validator->errors()->add($field, self::message($problem));
                    }
                }
            },
        ];
    }

    /** The template this request names. */
    public function template(): string
    {
        return (string) $this->route('template');
    }

    /**
     * What was typed, per editable field — trimmed, and NOT yet judged an
     * override: the controller decides that against the file.
     *
     * @return array<string, string>
     */
    public function texts(): array
    {
        $texts = [];

        foreach (app(EmailTemplates::class)->fieldsOf($this->template()) as $field) {
            $raw = $this->input($field);
            $texts[$field] = is_string($raw) ? trim($raw) : '';
        }

        return $texts;
    }

    /**
     * The sentence a problem is told in — the one the editor shows live, too.
     *
     * @param  array{code: string, names: list<string>, max?: int}  $problem
     */
    public static function message(array $problem): string
    {
        // Each token is isolated left-to-right (U+2066 … U+2069): inside an
        // Arabic sentence the colon of ":program" would otherwise jump to its
        // right and the administrator would be told a different token.
        $names = implode(
            (string) __('admin.email_editor.list_separator'),
            array_map(static fn (string $name): string => "\u{2066}:".$name."\u{2069}", $problem['names']),
        );

        return (string) __('admin.email_editor.errors.'.$problem['code'], [
            'names' => $names,
            'max' => $problem['max'] ?? '',
        ]);
    }
}
