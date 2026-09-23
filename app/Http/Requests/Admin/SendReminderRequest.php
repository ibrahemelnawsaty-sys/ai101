<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\BroadcastKind;
use App\Models\Broadcast;
use App\Models\Cohort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual reminder to one cohort: of its upcoming sessions, or of each
 * trainee's unsubmitted work (D-87). The automatic reminders are not touched.
 *
 * @see PRD §9.16.1, §9.18 · BR-28, BR-33 · D-83, D-87
 */
final class SendReminderRequest extends FormRequest
{
    /**
     * Its own bag: the send screen carries two forms with a cohort field each,
     * and a missing cohort here must not appear under the message form.
     *
     * @var string
     */
    protected $errorBag = 'reminder';

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', Broadcast::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cohort_id' => ['required', 'string', Rule::exists('cohorts', 'id')],
            'kind' => ['required', 'string', Rule::in([BroadcastKind::Sessions->value, BroadcastKind::Assignments->value])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cohort_id' => (string) __('admin.broadcasts.fields.cohort'),
        ];
    }

    public function cohort(): Cohort
    {
        /** @var Cohort $cohort */
        $cohort = Cohort::query()->whereKey((string) $this->validated('cohort_id'))->firstOrFail();

        return $cohort;
    }

    public function kind(): BroadcastKind
    {
        return BroadcastKind::from((string) $this->validated('kind'));
    }
}
