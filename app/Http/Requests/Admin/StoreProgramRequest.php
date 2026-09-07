<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ProgramStatus;
use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creating a programme. Every word a visitor reads about the programme is
 * stored here and edited from this screen — never written into the code
 * (BR-31, BR-36).
 *
 * The form and the table disagree on names, and this class is the only place
 * that knows both: the editor offers one `name`, one `summary` and three
 * one-item-per-line textareas, while `programs` stores `name_ar`, `name_en`,
 * `description` and three JSON lists. `columns()` below is the translation, in
 * the same shape StoreCohortRequest uses for the same reason.
 *
 * @see BR-31, BR-36 · PRD §4.2, §7.2 · CONSTITUTION Art. 6
 */
final class StoreProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', Program::class);
    }

    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug');
        $name = $this->input('name');

        $source = is_string($slug) && trim($slug) !== ''
            ? $slug
            : (is_string($name) ? $name : '');

        if ($source !== '') {
            $this->merge(['slug' => Str::slug($source)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9-]+$/', Rule::unique('programs', 'slug')],
            'summary' => ['required', 'string', 'max:5000'],
            'objectives' => ['nullable', 'string', 'max:6000'],
            'target_audience' => ['nullable', 'string', 'max:6000'],
            'certificates' => ['nullable', 'string', 'max:3000'],
            'hours' => ['required', 'integer', 'min:1', 'max:10000'],
            'status' => ['required', Rule::enum(ProgramStatus::class)],
        ];
    }

    /**
     * Validated payload rewritten with `programs` column names.
     *
     * The editor offers a single name field. `name_en` is seeded from it on
     * creation rather than left empty, because the column is NOT NULL and an
     * empty English name would surface on the certificate; correcting it is a
     * later edit, not a lost record. Raised as an open question with this slice.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();

        return [
            'name_ar' => $data['name'],
            'name_en' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['summary'],
            'objectives' => self::lines($data['objectives'] ?? null),
            'target_audience' => self::lines($data['target_audience'] ?? null),
            'certificates' => self::lines($data['certificates'] ?? null),
            'hours' => (int) $data['hours'],
            'status' => $data['status'],
        ];
    }

    /**
     * One textarea line becomes one list item; blank lines are dropped.
     *
     * @return list<string>
     */
    public static function lines(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $items = [];

        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $items[] = $line;
            }
        }

        return $items;
    }
}
