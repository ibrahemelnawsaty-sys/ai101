<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\FinalProject;
use App\Models\FinalProjectGuideVersion;
use App\Rules\GuidePageColours;
use App\Rules\SniffedFileType;
use App\Services\FinalProject\GuideContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Saving a guide page — typed or pasted into the editor, or uploaded as an
 * .html file that replaces it (D-127). The general supervisor only.
 *
 * The page is judged whole, whichever way it came: at most 2 MB, never empty,
 * and not a single yellow or gold colour in its styling (Article 14). An
 * upload's type is read from its bytes, not its name (Article 24). The page is
 * NOT cleaned here: it is stored as written and disarmed when served
 * (GuideDocument), so the history keeps exactly what was saved.
 *
 * @see D-127 · CONSTITUTION Art. 5, Art. 14, Art. 24
 */
final class SaveFinalProjectGuideRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof FinalProject && $user !== null && $user->can('update', $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'html' => ['required_without:file', 'nullable', 'string'],
            'file' => [
                'required_without:html',
                'nullable',
                'file',
                'max:'.intdiv(GuideContent::MAX_BYTES, 1024),
                'extensions:html,htm',
                new SniffedFileType(['text/html'], (string) __('admin.final_project.guide.errors.not_html')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'html.required_without' => (string) __('admin.final_project.guide.errors.empty'),
            'file.required_without' => (string) __('admin.final_project.guide.errors.empty'),
            'file.max' => (string) __('admin.final_project.guide.errors.too_large'),
            'file.extensions' => (string) __('admin.final_project.guide.errors.not_html'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $page = $this->page();
            $field = $this->hasFile('file') ? 'file' : 'html';

            if (trim($page) === '') {
                $validator->errors()->add($field, (string) __('admin.final_project.guide.errors.empty'));

                return;
            }

            if (strlen($page) > GuideContent::MAX_BYTES) {
                $validator->errors()->add($field, (string) __('admin.final_project.guide.errors.too_large'));

                return;
            }

            $offenders = GuidePageColours::offenders($page);

            if ($offenders !== []) {
                $validator->errors()->add($field, (string) __('admin.final_project.guide.errors.yellow', ['colours' => implode((string) __('admin.final_project.guide.errors.list_separator'), $offenders)]));
            }
        });
    }

    /** The page as it arrived: the uploaded file when there is one, else the editor. */
    public function page(): string
    {
        $file = $this->file('file');

        if ($file instanceof UploadedFile) {
            return (string) file_get_contents((string) $file->getRealPath());
        }

        return (string) $this->input('html', '');
    }

    public function source(): string
    {
        return $this->file('file') instanceof UploadedFile
            ? FinalProjectGuideVersion::SOURCE_UPLOAD
            : FinalProjectGuideVersion::SOURCE_EDITOR;
    }
}
