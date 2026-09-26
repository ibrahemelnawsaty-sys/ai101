<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Presenters\Shared\HandInReceipt;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The receipt page of a final-project hand-in — where its QR code leads
 * (D-122).
 *
 * A sign-in is required, and the hand-in's own policy decides who reads it:
 * the participant who handed it in, the trainer of that cohort, the general
 * supervisor. Anyone else — another trainee, a trainer of another cohort, a
 * coordinator — is answered 403, and the refusal lands in audit_logs with the
 * caller's address like every policy refusal (BR-22, BR-23). There is no
 * public version of this page: a scanned code discloses nothing to a stranger.
 *
 * A code that is not well formed never reaches the query (the route pattern);
 * one that names no hand-in is 404.
 *
 * @see BR-22, BR-23 · FR-NOTIF-15 · PRD §9.14.2 · D-122 · CONSTITUTION Art. 5, Art. 22
 */
final class FinalProjectReceiptController extends Controller
{
    public function __invoke(Request $request, string $code): View
    {
        $submission = ProjectSubmission::query()
            ->with(['finalProject', 'user.profile', 'latestEvaluation'])
            ->where('receipt_code', $code)
            ->first();

        $project = $submission?->getRelationValue('finalProject');

        if (! $submission instanceof ProjectSubmission || ! $project instanceof FinalProject) {
            abort(404);
        }

        $this->authorize('view', $submission);

        /** @var User $viewer */
        $viewer = $request->user();

        return view('final-project-receipt', [
            'receipt' => HandInReceipt::from(
                $submission,
                $project,
                (string) $submission->getAttribute('user_id') === (string) $viewer->getKey(),
            ),
            'errorState' => null,
        ]);
    }
}
