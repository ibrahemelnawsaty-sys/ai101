<?php

declare(strict_types=1);

/**
 * Every route on the platform, with the names and middleware fixed by
 * PROJECT-CONTRACT §10 and the sitemap in PRD §8.
 *
 * Three habits are kept without exception:
 *   1. A state-changing route never answers to GET. Reads are GET, writes are
 *      POST, PUT, PATCH or DELETE, so CSRF protection actually covers them
 *      (PRD §12.3).
 *   2. A protected route names its guard here — `auth`, `verified`, `role:…`,
 *      `cohort.scope`, `not.impersonating` — and the controller behind it
 *      authorises again through a policy. Route middleware is the outer fence,
 *      never the only one (CONSTITUTION Art. 5, Art. 22).
 *   3. Anything that acts *as the previewed user* carries `not.impersonating`,
 *      so account preview stays read-only even when the endpoint is called
 *      directly (BR-33).
 *
 * Rate limits come from the named limiters in App\Providers\RouteServiceProvider,
 * one per row of PRD §12.4.
 *
 * @see PRD §8, §12.3, §12.4 · PROJECT-CONTRACT §10 · CONSTITUTION Art. 5, Art. 22, Art. 23
 */

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CertificateController as AdminCertificateController;
use App\Http\Controllers\Admin\CohortController as AdminCohortController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\LandingController as AdminLandingController;
use App\Http\Controllers\Admin\ProgramController as AdminProgramController;
use App\Http\Controllers\Admin\RegistrationController as AdminRegistrationController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\FirstPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Participant\AssignmentController;
use App\Http\Controllers\Participant\AttendanceController;
use App\Http\Controllers\Participant\CardController;
use App\Http\Controllers\Participant\CertificateController;
use App\Http\Controllers\Participant\CohortSwitchController;
use App\Http\Controllers\Participant\DashboardController;
use App\Http\Controllers\Participant\FinalProjectController;
use App\Http\Controllers\Participant\GradeController;
use App\Http\Controllers\Participant\JourneyController;
use App\Http\Controllers\Participant\LiveController;
use App\Http\Controllers\Participant\MessageController;
use App\Http\Controllers\Participant\NotificationController;
use App\Http\Controllers\Participant\ProfileController;
use App\Http\Controllers\Participant\ResourceController;
use App\Http\Controllers\Participant\ScheduleController;
use App\Http\Controllers\Public\CardVerificationController;
use App\Http\Controllers\Public\CertificateVerificationController;
use App\Http\Controllers\Public\CrawlerController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\LegalController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\Public\ProgramDirectoryController;
use App\Http\Controllers\Public\WaitlistController;
use App\Http\Controllers\Trainer\AssignmentController as TrainerAssignmentController;
use App\Http\Controllers\Trainer\AttendanceController as TrainerAttendanceController;
use App\Http\Controllers\Trainer\FinalProjectController as TrainerFinalProjectController;
use App\Http\Controllers\Trainer\ParticipantController as TrainerParticipantController;
use App\Http\Controllers\Trainer\ReportController as TrainerReportController;
use App\Http\Controllers\Trainer\ResourceController as TrainerResourceController;
use App\Http\Controllers\Trainer\SessionController as TrainerSessionController;
use App\Http\Controllers\Trainer\SubmissionController as TrainerSubmissionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
| Readable without a session. Throttled per IP so a public page cannot be
| used as an amplifier (PRD §12.4).
*/

Route::middleware('throttle:public')->group(function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('home');

    Route::get('/terms', [LegalController::class, 'terms'])->name('terms');
    Route::get('/privacy', [LegalController::class, 'privacy'])->name('privacy');

    // D-39 — the standing public pages. `about` and `contact` are copy; the
    // directory is a scoped query. All three are GET-only and read-only, so the
    // public throttle above is the whole of their protection.
    Route::get('/about', [PageController::class, 'about'])->name('about');
    Route::get('/contact', [PageController::class, 'contact'])->name('contact');
    Route::get('/programs', [ProgramDirectoryController::class, 'index'])->name('programs');

    // BR-25 — both verification pages show the few facts the rule allows and
    // nothing else, to a visitor with no session at all.
    Route::get('/verify/{token}', [CardVerificationController::class, 'show'])
        ->name('card.verify');

    Route::get('/certificate/verify/{code}', [CertificateVerificationController::class, 'show'])
        ->name('certificate.verify');

    // PRD §9.1.3 requires both, and both answered 404 in production. Routes
    // rather than files: the deploy copies only build/, fonts/ and brand/ into
    // the web root, and both documents name the site's own host, which belongs
    // to APP_URL and not to a second copy in the repository (BR-36).
    Route::get('/robots.txt', [CrawlerController::class, 'robots'])->name('robots');
    Route::get('/sitemap.xml', [CrawlerController::class, 'sitemap'])->name('sitemap');
});

Route::post('/waitlist', [WaitlistController::class, 'store'])
    ->middleware('throttle:register')
    ->name('waitlist.store');

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
| Guest-only, and rate limited exactly as PRD §12.4 prescribes: five sign-in
| attempts per account per quarter hour, five registrations per address per
| hour, three recovery requests per address per hour.
*/

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('throttle:register')
        ->name('register.store');

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])
        ->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:password')
        ->name('password.email');

    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])
        ->name('password.reset');
    Route::post('/reset-password/{token}', [PasswordResetController::class, 'update'])
        ->middleware('throttle:password')
        ->name('password.update');
});

// The activation link is followed by someone who is not signed in yet, and
// signs them in when it works; it therefore cannot sit behind `guest`.
Route::get('/verify-email/{token}', [EmailVerificationController::class, 'verify'])
    ->middleware('throttle:public')
    ->name('verify-email');

Route::post('/verify-email/resend', [EmailVerificationController::class, 'send'])
    ->middleware('throttle:password')
    ->name('verification.send');

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| The forced first password (D-63)
|--------------------------------------------------------------------------
|
| An invited account signs in with a temporary password that was mailed to it
| and can reach nothing else until it has been replaced. `RequirePasswordChange`
| runs on the whole `web` stack and exempts these two names, so they are the
| only way out of that state.
|
| NO `throttle:password` HERE, DELIBERATELY. That limiter is
| `Limit::perHour(3)->by(emailKey($request))`, and `emailKey()` falls back to
| the IP whenever the request carries no `email` field — which this form does
| not. A cohort sitting in one training room behind one connection would share
| a single bucket of three attempts an hour, and the fourth trainee to set a
| password would be refused entry to the platform with nothing explaining why.
| `PUT /profile/password`, the same act from inside the account, carries no
| throttle either.
|
*/
Route::middleware('auth')->group(function (): void {
    Route::get('/first-password', [FirstPasswordController::class, 'edit'])
        ->name('password.first');

    Route::put('/first-password', [FirstPasswordController::class, 'update'])
        ->name('password.first.update');
});

/*
|--------------------------------------------------------------------------
| Dashboard — any signed-in account
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])->prefix('dashboard')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Switching the active cohort is a preference; the request refuses any
    // cohort the account cannot reach (PRD §4.4).
    Route::post('/cohort', CohortSwitchController::class)->name('cohort.switch');

    /*
     * Schedule — readable by anyone in the cohort.
     */
    Route::get('/schedule', [ScheduleController::class, 'index'])->name('schedule');
    Route::get('/schedule/print', [ScheduleController::class, 'print'])->name('schedule.pdf');
    Route::get('/schedule/calendar.ics', [ScheduleController::class, 'calendar'])->name('schedule.ics');
    Route::get('/schedule/{session}/calendar.ics', [ScheduleController::class, 'sessionCalendar'])
        ->name('schedule.session.ics');

    /*
     * Attendance. The two writes carry `not.impersonating` and the attendance
     * limiter: ten attempts per user per minute (BR-33, PRD §12.4).
     */
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/export', [AttendanceController::class, 'export'])->name('attendance.export');

    Route::post('/attendance/{session}/check-in', [AttendanceController::class, 'checkIn'])
        ->middleware(['role:participant', 'not.impersonating', 'throttle:attendance'])
        ->name('attendance.checkIn');

    Route::post('/attendance/{session}/check-out', [AttendanceController::class, 'checkOut'])
        ->middleware(['role:participant', 'not.impersonating', 'throttle:attendance'])
        ->name('attendance.checkOut');

    /*
     * Live sessions. `join` is a POST because it hands over a meeting URL after
     * re-checking the window on the server — it is an action, not a page (BR-24).
     */
    Route::get('/live', [LiveController::class, 'index'])->name('live');
    Route::post('/live/{session}/join', [LiveController::class, 'join'])->name('live.join');
    Route::get('/live/{session}/recording', [LiveController::class, 'recording'])->name('live.recording');

    /*
     * Assignments.
     */
    Route::get('/assignments', [AssignmentController::class, 'index'])->name('assignments.index');
    Route::get('/assignments/{assignment}', [AssignmentController::class, 'show'])->name('assignments.show');
    Route::post('/assignments/{assignment}/submit', [AssignmentController::class, 'submit'])
        ->middleware(['role:participant', 'not.impersonating', 'throttle:upload'])
        ->name('assignments.submit');

    /*
     * Training kit. Downloads are streamed after the policy check; the stored
     * path is never exposed (PRD §12.5).
     */
    Route::get('/resources', [ResourceController::class, 'index'])->name('resources.index');
    Route::get('/resources/{resource}/download', [ResourceController::class, 'download'])
        ->name('resources.download');
    Route::get('/resources/{resource}/preview', [ResourceController::class, 'preview'])
        ->name('resources.preview');

    /*
     * Messaging.
     */
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/{thread}/poll', [MessageController::class, 'poll'])->name('messages.poll');
    Route::post('/messages/{thread}', [MessageController::class, 'store'])
        ->middleware(['not.impersonating', 'throttle:messages'])
        ->name('messages.store');
    Route::patch('/messages/{message}', [MessageController::class, 'update'])
        ->middleware('not.impersonating')
        ->name('messages.edit');
    Route::post('/messages/{message}/report', [MessageController::class, 'report'])
        ->middleware('not.impersonating')
        ->name('messages.report');

    /*
     * Notifications. BR-34 — no read receipt is written during a preview.
     */
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->middleware('not.impersonating')
        ->name('notifications.readAll');

    /*
     * Account.
     */
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->middleware(['not.impersonating', 'throttle:upload'])
        ->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])
        ->middleware('not.impersonating')
        ->name('profile.password');
    Route::put('/profile/notifications', [ProfileController::class, 'notifications'])
        ->middleware('not.impersonating')
        ->name('profile.notifications');
    Route::delete('/profile/sessions', [ProfileController::class, 'destroySessions'])
        ->middleware('not.impersonating')
        ->name('profile.sessions.destroy');

    /*
     * Participant-only screens.
     */
    Route::middleware('role:participant')->group(function (): void {
        Route::get('/card', [CardController::class, 'show'])->name('participant.card');
        // The browser makes the PDF; download() read a column that does not
        // exist on this table and always answered 404 (D-57).
        Route::get('/card/print', [CardController::class, 'print'])->name('participant.card.print');

        Route::get('/journey', JourneyController::class)->name('participant.journey');

        Route::get('/final-project', [FinalProjectController::class, 'show'])->name('finalProject');
        Route::post('/final-project/submit', [FinalProjectController::class, 'submit'])
            ->middleware(['not.impersonating', 'throttle:upload'])
            ->name('finalProject.submit');

        Route::get('/grades', [GradeController::class, 'index'])->name('grades');
        Route::get('/grades/export', [GradeController::class, 'export'])->name('grades.export');

        Route::get('/certificate', [CertificateController::class, 'show'])->name('certificate');
        Route::get('/certificate/download', [CertificateController::class, 'download'])
            ->name('certificate.download');
        Route::get('/certificate/accredited', [CertificateController::class, 'accredited'])
            ->name('certificate.tvtc');
    });
});

/*
|--------------------------------------------------------------------------
| Trainer
|--------------------------------------------------------------------------
| `cohort.scope` resolves, from the enrolments table, the single cohort this
| request may act on, and refuses any other with 403 and an audit entry
| (BR-23). Administrators reach these screens too, per PRD §4.2.
*/

Route::middleware(['auth', 'verified', 'role:trainer,admin', 'cohort.scope'])
    ->prefix('trainer')
    ->name('trainer.')
    ->group(function (): void {
        Route::get('/submissions', [TrainerSubmissionController::class, 'index'])->name('submissions');
        Route::get('/submissions/export', [TrainerSubmissionController::class, 'export'])
            ->name('submissions.export');
        Route::post('/submissions/{submission}/grade', [TrainerSubmissionController::class, 'grade'])
            ->middleware('not.impersonating')
            ->name('submissions.grade');
        Route::patch('/evaluations/{evaluation}', [TrainerSubmissionController::class, 'revise'])
            ->middleware('not.impersonating')
            ->name('submissions.revise');
        Route::post('/assignments/{assignment}/remind', [TrainerSubmissionController::class, 'remind'])
            ->middleware('not.impersonating')
            ->name('submissions.remind');
        Route::get('/assignments/{assignment}/download-all', [TrainerSubmissionController::class, 'bulkDownload'])
            ->name('submissions.bulkDownload');

        Route::get('/attendance', [TrainerAttendanceController::class, 'index'])->name('attendance');
        Route::get('/attendance/export', [TrainerAttendanceController::class, 'export'])
            ->name('attendance.export');
        Route::get('/attendance/{session}/poll', [TrainerAttendanceController::class, 'poll'])
            ->name('attendance.poll');
        Route::patch('/attendance/{attendance}', [TrainerAttendanceController::class, 'update'])
            ->middleware('not.impersonating')
            ->name('attendance.update');
        Route::post('/attendance/{session}/bulk', [TrainerAttendanceController::class, 'bulk'])
            ->middleware('not.impersonating')
            ->name('attendance.bulk');

        Route::get('/assignments', [TrainerAssignmentController::class, 'index'])->name('assignments');
        Route::post('/assignments', [TrainerAssignmentController::class, 'store'])
            ->middleware(['not.impersonating', 'throttle:upload'])
            ->name('assignments.store');
        Route::patch('/assignments/{assignment}', [TrainerAssignmentController::class, 'update'])
            ->middleware(['not.impersonating', 'throttle:upload'])
            ->name('assignments.update');

        Route::get('/sessions', [TrainerSessionController::class, 'index'])->name('sessions');
        Route::post('/sessions', [TrainerSessionController::class, 'store'])
            ->middleware('not.impersonating')
            ->name('sessions.store');
        Route::patch('/sessions/{session}', [TrainerSessionController::class, 'update'])
            ->middleware('not.impersonating')
            ->name('sessions.update');
        Route::post('/sessions/{session}/cancel', [TrainerSessionController::class, 'cancel'])
            ->middleware('not.impersonating')
            ->name('sessions.cancel');

        Route::get('/resources', [TrainerResourceController::class, 'index'])->name('resources');
        Route::post('/resources', [TrainerResourceController::class, 'store'])
            ->middleware(['not.impersonating', 'throttle:upload'])
            ->name('resources.store');
        Route::delete('/resources/{resource}', [TrainerResourceController::class, 'archive'])
            ->middleware('not.impersonating')
            ->name('resources.archive');

        Route::get('/final-project', [TrainerFinalProjectController::class, 'index'])->name('finalProject');
        Route::put('/final-project/{project}/unlock', [TrainerFinalProjectController::class, 'unlock'])
            ->middleware('not.impersonating')
            ->name('finalProject.unlock');
        Route::post('/final-project/{submission}/grade', [TrainerFinalProjectController::class, 'grade'])
            ->middleware('not.impersonating')
            ->name('finalProject.grade');

        Route::get('/participants', [TrainerParticipantController::class, 'index'])->name('participants');
        Route::get('/participants/export', [TrainerParticipantController::class, 'export'])
            ->name('participants.export');

        Route::get('/reports', [TrainerReportController::class, 'index'])->name('reports');
        Route::get('/reports/export', [TrainerReportController::class, 'export'])->name('reports.export');
    });

/*
|--------------------------------------------------------------------------
| Administration
|--------------------------------------------------------------------------
| Every route is admin-only and every write is refused while an account
| preview is running (BR-33). Ending a preview is the one exception, and it
| lives outside this group because the person calling it is, at that moment,
| signed in as somebody else.
*/

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');

        Route::get('/programs', [AdminProgramController::class, 'index'])->name('programs.index');
        Route::post('/programs', [AdminProgramController::class, 'store'])
            ->middleware('not.impersonating')
            ->name('programs.store');
        Route::patch('/programs/{program}', [AdminProgramController::class, 'update'])
            ->middleware('not.impersonating')
            ->name('programs.update');
        Route::put('/programs/{program}/archive', [AdminProgramController::class, 'archive'])
            ->middleware('not.impersonating')
            ->name('programs.archive');

        Route::get('/cohorts', [AdminCohortController::class, 'index'])->name('cohorts.index');
        Route::post('/cohorts', [AdminCohortController::class, 'store'])
            ->middleware('not.impersonating')
            ->name('cohorts.store');
        Route::patch('/cohorts/{cohort}', [AdminCohortController::class, 'update'])
            ->middleware('not.impersonating')
            ->name('cohorts.update');

        // Assigning a trainer to a cohort *is* the permission that
        // `cohort.scope` and the policies read afterwards (BR-23).
        Route::post('/cohorts/{cohort}/trainers', [AdminCohortController::class, 'attachTrainer'])
            ->middleware('not.impersonating')
            ->name('cohorts.trainers.attach');
        Route::delete('/cohorts/{cohort}/trainers/{trainer}', [AdminCohortController::class, 'detachTrainer'])
            ->middleware('not.impersonating')
            ->name('cohorts.trainers.detach');

        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [AdminUserController::class, 'create'])->name('users.create');
        Route::get('/users/export', [AdminUserController::class, 'export'])->name('users.export');
        Route::post('/users', [AdminUserController::class, 'store'])
            ->middleware('not.impersonating')
            ->name('users.store');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::patch('/users/{user}', [AdminUserController::class, 'update'])
            ->middleware('not.impersonating')
            ->name('users.update');
        Route::put('/users/{user}/role', [AdminUserController::class, 'changeRole'])
            ->middleware('not.impersonating')
            ->name('users.role');
        Route::patch('/users/{user}/status', [AdminUserController::class, 'status'])
            ->middleware('not.impersonating')
            ->name('users.status');
        Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])
            ->middleware(['not.impersonating', 'throttle:password'])
            ->name('users.resetPassword');
        Route::post('/users/{user}/resend-verification', [AdminUserController::class, 'resendVerification'])
            ->middleware(['not.impersonating', 'throttle:password'])
            ->name('users.resendVerification');
        Route::post('/users/{user}/logout-everywhere', [AdminUserController::class, 'logoutEverywhere'])
            ->middleware('not.impersonating')
            ->name('users.logoutEverywhere');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])
            ->middleware('not.impersonating')
            ->name('users.destroy');

        // BR-35 — the policy refuses another administrator, a deleted account
        // and one's own account before this ever starts.
        Route::post('/users/{user}/preview', [ImpersonationController::class, 'start'])
            ->middleware('not.impersonating')
            ->name('users.preview');

        Route::get('/registrations', [AdminRegistrationController::class, 'index'])->name('registrations.index');
        Route::get('/registrations/export', [AdminRegistrationController::class, 'export'])
            ->name('registrations.export');
        Route::put('/registrations/{enrollment}/approve', [AdminRegistrationController::class, 'approve'])
            ->middleware('not.impersonating')
            ->name('registrations.approve');
        Route::put('/registrations/{enrollment}/reject', [AdminRegistrationController::class, 'reject'])
            ->middleware('not.impersonating')
            ->name('registrations.reject');

        Route::get('/certificates', [AdminCertificateController::class, 'index'])->name('certificates.index');
        Route::get('/certificates/export', [AdminCertificateController::class, 'export'])
            ->name('certificates.export');
        Route::post('/certificates', [AdminCertificateController::class, 'issue'])
            ->middleware('not.impersonating')
            ->name('certificates.issue');
        Route::post('/certificates/bulk', [AdminCertificateController::class, 'issueBulk'])
            ->middleware('not.impersonating')
            ->name('certificates.issueBulk');
        // BR-26 — issuing against the rules is its own endpoint, so the trail
        // records an override as an override and never as an ordinary issue.
        Route::post('/certificates/{user}/override', [AdminCertificateController::class, 'override'])
            ->middleware('not.impersonating')
            ->name('certificates.override');
        Route::post('/certificates/{certificate}/reissue', [AdminCertificateController::class, 'reissue'])
            ->middleware('not.impersonating')
            ->name('certificates.reissue');
        Route::delete('/certificates/{certificate}', [AdminCertificateController::class, 'revoke'])
            ->middleware('not.impersonating')
            ->name('certificates.revoke');

        Route::get('/landing', [AdminLandingController::class, 'edit'])->name('landing.edit');
        Route::put('/landing', [AdminLandingController::class, 'update'])
            ->middleware('not.impersonating')
            ->name('landing.update');
        Route::post('/landing/faq', [AdminLandingController::class, 'storeFaq'])
            ->middleware('not.impersonating')
            ->name('landing.faq.store');
        Route::put('/landing/faq/{entry}', [AdminLandingController::class, 'updateFaq'])
            ->middleware('not.impersonating')
            ->name('landing.faq.update');
        Route::delete('/landing/faq/{entry}', [AdminLandingController::class, 'destroyFaq'])
            ->middleware('not.impersonating')
            ->name('landing.faq.destroy');

        Route::get('/reports', [AdminReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [AdminReportController::class, 'export'])->name('reports.export');

        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');

        Route::get('/settings', [AdminSettingController::class, 'edit'])->name('settings.edit');
        Route::get('/settings/templates/{template}', [AdminSettingController::class, 'template'])
            ->name('settings.template');
        Route::put('/settings', [AdminSettingController::class, 'update'])
            ->middleware('not.impersonating')
            ->name('settings.update');
        Route::put('/settings/notifications', [AdminSettingController::class, 'notifications'])
            ->middleware('not.impersonating')
            ->name('settings.notifications');
    });

/*
 * Ending a preview. Only `auth` guards it, deliberately: the session belongs to
 * the previewed account at that moment, so an `role:admin` check would lock the
 * administrator inside the preview they are trying to leave. The service
 * verifies the stored preview payload before restoring anything (PRD §4.5.2).
 */
Route::delete('/admin/impersonation', [ImpersonationController::class, 'stop'])
    ->middleware('auth')
    ->name('admin.impersonation.stop');
