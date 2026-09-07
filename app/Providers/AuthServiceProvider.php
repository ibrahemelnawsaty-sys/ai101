<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\DigitalCard;
use App\Models\Enrollment;
use App\Models\Evaluation;
use App\Models\FinalProject;
use App\Models\LandingSetting;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Program;
use App\Models\ProjectSubmission;
use App\Models\Resource;
use App\Models\Session;
use App\Models\Submission;
use App\Models\Thread;
use App\Models\User;
use App\Models\Week;
use App\Policies\AssignmentPolicy;
use App\Policies\AttendancePolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\CertificatePolicy;
use App\Policies\CohortPolicy;
use App\Policies\DigitalCardPolicy;
use App\Policies\EnrollmentPolicy;
use App\Policies\EvaluationPolicy;
use App\Policies\FinalProjectPolicy;
use App\Policies\LandingSettingPolicy;
use App\Policies\MessagePolicy;
use App\Policies\NotificationPolicy;
use App\Policies\ProgramPolicy;
use App\Policies\ProjectSubmissionPolicy;
use App\Policies\ResourcePolicy;
use App\Policies\SessionPolicy;
use App\Policies\SubmissionPolicy;
use App\Policies\ThreadPolicy;
use App\Policies\UserPolicy;
use App\Policies\WeekPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Authorization wiring.
 *
 * The map below is written out rather than left to Laravel's convention-based
 * discovery on purpose: an unmapped model silently falls back to "deny", which
 * looks identical to a policy that exists and refuses. Being explicit means a
 * missing policy is a missing line here, and reviewers can read the whole
 * authorization surface in one screen.
 *
 * There is deliberately NO Gate::before() granting administrators everything.
 * A blanket bypass would sail straight past the read-only preview guard
 * (BR-33) and past cohort scoping (BR-22, BR-23). Administrator reach is
 * granted policy by policy, as an allow-list (art. 22).
 *
 * @see BR-22, BR-23, BR-28, BR-33 · PRD §4.2, §4.3 · CONSTITUTION art. 5, art. 22
 */
final class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private array $policies = [
        User::class => UserPolicy::class,
        Program::class => ProgramPolicy::class,
        Cohort::class => CohortPolicy::class,
        Week::class => WeekPolicy::class,
        Session::class => SessionPolicy::class,
        Attendance::class => AttendancePolicy::class,
        Assignment::class => AssignmentPolicy::class,
        Submission::class => SubmissionPolicy::class,
        Evaluation::class => EvaluationPolicy::class,
        FinalProject::class => FinalProjectPolicy::class,
        ProjectSubmission::class => ProjectSubmissionPolicy::class,
        Resource::class => ResourcePolicy::class,
        Thread::class => ThreadPolicy::class,
        Message::class => MessagePolicy::class,
        Certificate::class => CertificatePolicy::class,
        DigitalCard::class => DigitalCardPolicy::class,
        Notification::class => NotificationPolicy::class,
        Enrollment::class => EnrollmentPolicy::class,
        LandingSetting::class => LandingSettingPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
