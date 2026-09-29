<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Mail\EmailTemplates;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A draft of a template, to be rendered as its letter — and thrown away.
 *
 * Reading only: it writes nothing and sends nothing, so it is not refused in an
 * account preview's own name but only by the role (the system administrator's,
 * D-117). It is lenient by design: a draft that drops a value, or names one the
 * letter does not supply, still renders — the picture shows the gap — and the
 * problems are told alongside it, in the same sentences a save would refuse
 * with. Only the length is a hard limit, so a stuck key cannot post a novel.
 *
 * @see BR-31 · PRD §9.16 · CONSTITUTION art. 5, art. 22 · D-114, D-117, D-136
 */
final class PreviewEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('console.settings')) {
            return false;
        }

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
            $rules[$field] = ['nullable', 'string', 'max:'.EmailTemplates::PREVIEW_CEILING];
        }

        return $rules;
    }

    public function template(): string
    {
        return (string) $this->route('template');
    }

    /**
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
}
