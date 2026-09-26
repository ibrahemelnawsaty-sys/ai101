<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\FinalProjectHandedIn;
use App\Mail\HandInReceiptLetter;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\FinalProject\SubmissionFields;
use App\Services\Mail\MailPreferences;
use App\Support\Dates;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Writes the hand-in receipt to the participant who handed in (D-122): the
 * receipt code, a QR that opens the receipt page, what was handed in, and the
 * next step — waiting for the evaluation.
 *
 * Read at SEND time from the stored hand-in, not from the request (D-51). The
 * participant's own switch for "submission received" letters is honoured
 * (D-66); the notice on the platform is written at the moment of the hand-in,
 * by the controller, as every in-app notice is.
 *
 * A letter that cannot be written is logged by its class only — the message
 * may carry personal data (art. 12) — and never undoes the hand-in.
 *
 * @see FR-NOTIF-15 · PRD §9.14.2, §9.16.1 · D-51, D-66, D-122
 */
final class SendFinalProjectReceipt implements ShouldQueue
{
    public function __construct(private readonly MailPreferences $preferences) {}

    public function handle(FinalProjectHandedIn $event): void
    {
        $submission = ProjectSubmission::query()
            ->with(['finalProject', 'user'])
            ->find($event->submissionId);

        $project = $submission?->getRelationValue('finalProject');
        $user = $submission?->getRelationValue('user');
        $code = $submission?->getAttribute('receipt_code');

        if (! $submission instanceof ProjectSubmission || ! $project instanceof FinalProject || ! $user instanceof User || ! is_string($code) || $code === '') {
            return;
        }

        $email = (string) $user->getAttribute('email');

        if ($email === '' || ! $this->preferences->allows($user, 'submission_received')) {
            return;
        }

        $items = [];

        foreach (SubmissionFields::read($submission->getAttribute('answers')) as $answer) {
            $filled = $answer['type']->isFile() ? $answer['files'] !== [] : $answer['value'] !== null;

            if ($filled && $answer['label'] !== '') {
                $items[] = $answer['label'];
            }
        }

        try {
            Mail::to($email)->send(new HandInReceiptLetter(
                copyKey: 'emails.final_project_received',
                values: [
                    'code' => $code,
                    'project' => (string) $project->getAttribute('title'),
                ],
                receiptCode: $code,
                receiptUrl: route('finalProject.receipt', ['code' => $code]),
                meta: [
                    'project.receipt.details.project' => (string) $project->getAttribute('title'),
                    'project.receipt.details.submitted_at' => Dates::dateTime($submission->getAttribute('submitted_at')),
                    'project.receipt.details.version' => (string) (int) $submission->getAttribute('version'),
                ],
                items: $items,
            ));
        } catch (\Throwable $exception) {
            Log::warning('mail.final_project_receipt_failed', [
                'submission_id' => $submission->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
