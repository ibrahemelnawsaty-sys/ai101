<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Mail\EmailTemplates;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Back to the original" for one e-mail template: its overrides are deleted, so
 * the letters follow the file again (BR-31, D-136).
 *
 * The system administrator's, and never from inside an account preview
 * (`console.settings` refuses both). The role is asked first, so an outsider
 * cannot learn which templates exist.
 *
 * @see BR-31, BR-33 · PRD §9.18 · CONSTITUTION art. 5, art. 22 · D-114, D-117, D-136
 */
final class ResetEmailTemplateRequest extends FormRequest
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
        return [];
    }

    public function template(): string
    {
        return (string) $this->route('template');
    }
}
