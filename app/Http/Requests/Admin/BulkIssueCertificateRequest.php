<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issuing certificates to a whole cohort at once (PRD §9.17).
 *
 * There is deliberately no override field here. An override is a judgement
 * about one person and needs its own written reason; a bulk action that could
 * carry one would turn BR-26 into a checkbox.
 *
 * Each named account must already be enrolled in the named cohort, so the list
 * cannot reach outside it.
 *
 * @see BR-25, BR-26, BR-33 · PRD §9.17 · CONSTITUTION Art. 22
 */
final class BulkIssueCertificateRequest extends FormRequest
{
    /** Ceiling on one call, so a request never becomes a long job (Art. 10). */
    public const MAX_CANDIDATES = 200;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('issue', Certificate::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cohort_id' => ['required', 'string', 'uuid', Rule::exists('cohorts', 'id')->whereNull('deleted_at')],
            'user_id' => ['required', 'array', 'min:1', 'max:'.self::MAX_CANDIDATES],
            'user_id.*' => [
                'required', 'string', 'uuid',
                Rule::exists('enrollments', 'user_id')->where('cohort_id', $this->input('cohort_id')),
            ],
        ];
    }

    public function cohort(): Cohort
    {
        /** @var Cohort $cohort */
        $cohort = Cohort::query()->findOrFail($this->validated('cohort_id'));

        return $cohort;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function candidates()
    {
        /** @var list<string> $ids */
        $ids = array_values(array_unique((array) $this->validated('user_id')));

        return User::query()->whereIn('id', $ids)->get();
    }
}
