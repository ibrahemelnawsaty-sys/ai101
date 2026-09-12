<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirming a previewed import — the request that queues account creation.
 *
 * WHY IT EXISTS
 * `UserImportController::store()` took a plain `Request` and authorised inside
 * the method. That satisfies the policy and the scope, and not the third leg:
 * Article 5 requires ALL THREE — FormRequest, Policy, scoped query — on every
 * state-changing endpoint, and this is the most state-changing endpoint on the
 * platform: one press queues up to two hundred accounts. It was written that
 * way in D-63 and caught by the day-one audit (D-66).
 *
 * The confirm carries no fields of its own. The sheet it acts on was stored at
 * preview time and its path lives in the SESSION, never in the form — so a
 * different administrator cannot point the confirm at somebody else's upload.
 * That is why `rules()` is empty and why that emptiness is correct rather than
 * an omission: there is nothing in the request body to trust.
 *
 * @see PRD §4.2 · CONSTITUTION Art. 5 · D-63, D-66
 */
final class ConfirmUserImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
