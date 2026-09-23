<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Trainer\DecideAttendanceExceptionRequest;
use App\Models\AttendanceExceptionRequest;
use App\Services\Attendance\AttendanceExceptionRequester;
use Illuminate\Http\RedirectResponse;

/**
 * A coordinator's, trainer's or admin's decision on a participant's excuse
 * request (D-106) — approve or reject, mirroring
 * Admin\RegistrationController::approve()/reject() exactly. The pending queue
 * itself is a section of trainer/attendance.blade.php, built by
 * AttendanceController::index(); this controller only ever decides one row.
 *
 * @see D-106 · CONSTITUTION Art. 22
 */
final class AttendanceExceptionController extends Controller
{
    public function __construct(
        private readonly AttendanceExceptionRequester $exceptions,
    ) {}

    /**
     * `$exceptionRequest` is declared here, not only read off the FormRequest,
     * because implicit route-model-binding resolves from the CONTROLLER
     * ACTION's own signature — without it here, `$this->route(...)` inside
     * DecideAttendanceExceptionRequest::authorize() would still be a raw
     * string id, and every decision would 403 (same reason
     * RegistrationController::approve() takes `Enrollment $enrollment`
     * alongside its FormRequest).
     */
    public function approve(DecideAttendanceExceptionRequest $request, AttendanceExceptionRequest $exceptionRequest): RedirectResponse
    {
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        $this->exceptions->approve($actor, $exceptionRequest);

        return back()->with('status', __('attendance.exception_approved'));
    }

    public function reject(DecideAttendanceExceptionRequest $request, AttendanceExceptionRequest $exceptionRequest): RedirectResponse
    {
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        $this->exceptions->reject($actor, $exceptionRequest, (string) $request->reason());

        return back()->with('status', __('attendance.exception_rejected_saved'));
    }
}
