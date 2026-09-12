<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\CohortStatus;
use App\Enums\EmailTokenType;
use App\Enums\Gender;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Auth\Concerns\IssuesEmailTokens;
use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeUserRoleRequest;
use App\Http\Requests\Admin\ChangeUserStatusRequest;
use App\Http\Requests\Admin\EnrollUserRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\SuspendUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\AuditLog;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Profile;
use App\Models\User;
use App\Presenters\Admin\UserCounts;
use App\Presenters\Admin\UserProfile;
use App\Presenters\Admin\UserRow;
use App\Presenters\Support\Options;
use App\Services\Audit\AuditLogger;
use App\Services\Credentials\AccountInviter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Accounts and roles (PRD §4.2, §4.5.1, §9.18).
 *
 * Two rules are absolute here and are enforced by the policy and again in this
 * controller, because losing them locks the centre out of its own platform:
 * an administrator never deletes their own account, and at least one active
 * administrator must always remain (BR-32).
 *
 * Deletion is a soft delete. The records attached to an account — attendance,
 * evaluations, certificates — are never touched (PRD §4.4, §7.8).
 *
 * @see BR-22, BR-29, BR-32, BR-33 · PRD §4.2, §4.4, §9.18 · CONSTITUTION Art. 8, Art. 22
 */
final class UserController extends Controller
{
    use ExportsCsv;
    use IssuesEmailTokens;

    private const PER_PAGE = 50;

    /** How many trail entries the profile page shows before "view all". */
    private const AUDIT_LIMIT = 10;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountInviter $inviter,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        /** @var User $viewer */
        $viewer = $request->user();

        return view('admin.users.index', array_merge($this->listing($request, $viewer), [
            'errorState' => null,
        ]));
    }

    /**
     * Everything the accounts table needs, shaped once so that `index()` and
     * `create()` cannot drift apart.
     *
     * @return array<string, mixed>
     */
    private function listing(Request $request, User $viewer): array
    {
        $query = User::query()->with('profile')->orderByDesc('created_at');

        $search = $request->query('q');

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->where('email', 'like', $term);
        }

        $role = $request->query('role');

        if (is_string($role) && UserRole::tryFrom($role) !== null) {
            $query->where('role', $role);
        }

        $status = $request->query('status');

        if (is_string($status) && UserStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        return [
            'contextLabel' => null,
            'users' => $query->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(static fn (User $row): UserRow => UserRow::from($row, $viewer)),
            'counts' => $this->counts(),
            'roleOptions' => Options::fromEnum(UserRole::class),
            'statusOptions' => Options::fromEnum(UserStatus::class),
        ];
    }

    /** The four counters above the table. */
    private function counts(): UserCounts
    {
        return UserCounts::of(
            participants: User::query()->where('role', UserRole::Participant->value)->count(),
            trainers: User::query()->where('role', UserRole::Trainer->value)->count(),
            admins: User::query()->where('role', UserRole::Admin->value)->count(),
            pending: User::query()->where('status', UserStatus::Pending->value)->count(),
        );
    }

    /** The full profile of one account (PRD §4.5.1) — read only. */
    public function show(Request $request, User $user): View
    {
        $this->authorize('view', $user);

        /** @var User $viewer */
        $viewer = $request->user();

        $user->load(['profile', 'enrollments.cohort.program']);

        /** @var \Illuminate\Support\Collection<int, Enrollment> $enrollments */
        $enrollments = $user->getRelation('enrollments');

        return view('admin.users.show', [
            'contextLabel' => null,
            'user' => UserProfile::from(
                $user,
                $viewer,
                $enrollments,
                AuditLog::query()
                    ->with('actor.profile')
                    ->where('entity_id', $user->getKey())
                    ->orderByDesc('created_at')
                    ->limit(self::AUDIT_LIMIT)
                    ->get(),
            ),
            'roleOptions' => Options::fromEnum(UserRole::class),
            'statusOptions' => Options::fromEnum(UserStatus::class),
            // The cohorts this account may still be seated in: not finished,
            // and not one it already has an enrolment in (D-84).
            'enrollOptions' => Options::fromModels(
                Cohort::query()
                    ->whereIn('status', array_map(
                        static fn (CohortStatus $s): string => $s->value,
                        EnrollUserRequest::SEATABLE,
                    ))
                    ->whereNotIn('id', $enrollments->map(static fn (Enrollment $e): string => (string) $e->getAttribute('cohort_id'))->all())
                    ->orderByDesc('start_date')
                    ->get(),
                static fn (Cohort $cohort): string => (string) $cohort->getAttribute('name'),
            ),
            'errorState' => null,
        ]);
    }

    /**
     * Seat an existing participant account in a cohort: the enrolment, the
     * card and the cohort's conversations, as an invitation gives them
     * (D-84). Before this, an account created without a cohort had no way in
     * from the interface (D-69).
     */
    public function enroll(EnrollUserRequest $request, User $user): RedirectResponse
    {
        $seated = $this->inviter->enrollExisting($request->subject(), $request->cohort());

        return back()->with(
            $seated ? 'status' : 'warning',
            __($seated ? 'admin.users.enrolled' : 'admin.users.enroll_already'),
        );
    }

    /**
     * Create one account and invite it.
     *
     * This used to create a User and a Profile and then simply redirect: no
     * letter, no cohort, and nothing anywhere that could put the account in one
     * (D-63). Every part of that now lives in AccountInviter, which the bulk
     * import calls too — the seating, the temporary password and its expiry are
     * exactly the details that drift when they are written twice.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $created = $this->inviter->invite(
            email: (string) $request->validated('email'),
            role: UserRole::from((string) $request->validated('role')),
            profileColumns: $request->profileAttributes(),
            cohort: $request->cohort(),
        );

        return redirect()
            ->route('admin.users.show', $created)
            ->with('status', __('admin.users.invited'));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $subject = $user;

        $profile = Profile::query()->where('user_id', $subject->getKey())->firstOrFail();

        $before = $this->audit->snapshot($profile, ['phone']);

        $profile->fill($request->profileAttributes());

        $this->audit->log('user.updated', $subject, $before, $this->audit->snapshot($profile, ['phone']));

        $profile->save();

        return back()->with('status', __('admin.users.updated'));
    }

    /**
     * Changing a role is its own endpoint so it is audited on its own and can
     * never ride inside a details edit. BR-32 is re-checked here: an
     * administrator may not demote the last remaining active administrator.
     */
    public function changeRole(ChangeUserRoleRequest $request, User $user): RedirectResponse
    {
        $subject = $user;
        $role = $request->role();

        if ($subject->role === UserRole::Admin
            && $role !== UserRole::Admin
            && ! $this->otherActiveAdminsExist($subject)) {
            return back()->withErrors(['role' => __('admin.users.last_admin')]);
        }

        $before = ['role' => $subject->role->value];

        $subject->setAttribute('role', $role->value);

        $this->audit->log('user.role_changed', $subject, $before, [
            'role' => $role->value,
            // Without this the row records that a role changed and never why.
            'reason' => $request->validated('reason'),
        ]);

        $subject->save();

        return back()->with('status', __('admin.users.role_changed'));
    }

    /** The "create account" form; the same screen, with its editor open. */
    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        /** @var User $viewer */
        $viewer = $request->user();

        // It used to return `admin.users.index` with `'creating' => true`, and
        // that template never read the flag — so the "add a user" button
        // rendered the list again and appeared to do nothing. There was no
        // create form in the platform at all (D-63).
        return view('admin.users.create', [
            'contextLabel' => null,
            'roleOptions' => Options::fromEnum(UserRole::class),
            'genderOptions' => Options::fromEnum(Gender::class),
            'cohortOptions' => Options::fromModels(
                Cohort::query()->with('program')->orderByDesc('start_date')->get(),
                static fn (Cohort $cohort): string => (string) $cohort->getAttribute('name'),
            ),
            'errorState' => null,
        ]);
    }

    /**
     * Activating or suspending an account. Suspension ends the account's
     * sessions immediately, because a session that outlives the suspension is
     * not a suspension (PRD §4.4).
     */
    public function status(ChangeUserStatusRequest $request, User $user): RedirectResponse
    {
        $subject = $user;
        $target = $request->target();

        if ($subject->role === UserRole::Admin
            && $target !== UserStatus::Active
            && ! $this->otherActiveAdminsExist($subject)) {
            return back()->withErrors(['status' => __('admin.users.last_admin')]);
        }

        $before = ['status' => $subject->status->value];

        $subject->setAttribute('status', $target->value);

        $this->audit->log(
            action: $target === UserStatus::Active ? 'user.activated' : 'user.suspended',
            entity: $subject,
            before: $before,
            after: ['status' => $target->value],
        );

        $subject->save();

        if ($target !== UserStatus::Active) {
            $this->destroySessionsOf($subject);
        }

        return back()->with('status', __('admin.users.status_changed'));
    }

    /**
     * Send the account a recovery link. The administrator never sees, sets or
     * learns a password: hashes cannot be read back, and this endpoint does not
     * try (PRD §4.5.2).
     */
    public function resetPassword(User $user): RedirectResponse
    {
        $this->authorize('resetPassword', $user);

        $this->issueToken($user, EmailTokenType::Reset);

        $this->audit->log('user.password_reset_sent', $user);

        return back()->with('status', __('admin.users.reset_password_sent'));
    }

    /** Send the activation link again to an account that never confirmed. */
    public function resendVerification(User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        if ($user->getAttribute('email_verified_at') !== null) {
            return back()->withErrors(['email' => __('admin.users.already_verified')]);
        }

        $this->issueToken($user, EmailTokenType::Verify);

        $this->audit->log('user.verification_resent', $user);

        return back()->with('status', __('admin.users.verification_resent'));
    }

    /** End every session of one account, without changing its status. */
    public function logoutEverywhere(User $user): RedirectResponse
    {
        $this->authorize('logoutEverywhere', $user);

        $this->destroySessionsOf($user);

        $user->forceFill(['remember_token' => null])->save();

        $this->audit->log('user.sessions_revoked', $user);

        return back()->with('status', __('admin.users.sessions_revoked'));
    }

    /** The account list as a CSV. Never a password, never a token. */
    public function export(): Response
    {
        $this->authorize('viewAny', User::class);

        $rows = User::query()
            ->with('profile')
            ->orderBy('created_at')
            ->get()
            ->map(static fn (User $user): array => [
                (string) ($user->profile?->getAttribute('full_name_ar') ?? ''),
                (string) $user->getAttribute('email'),
                (string) ($user->profile?->getAttribute('phone') ?? ''),
                (string) $user->getAttribute('role')?->value,
                (string) $user->getAttribute('status')?->value,
            ])
            ->values()
            ->all();

        return $this->csvResponse([
            __('admin.users.export.name'),
            __('admin.users.export.email'),
            __('admin.users.export.phone'),
            __('admin.users.export.role'),
            __('admin.users.export.status'),
        ], $rows, 'athar-users.csv');
    }

    /**
     * A soft delete, never a destruction. BR-32 again: never oneself, and never
     * the last active administrator.
     */
    public function destroy(SuspendUserRequest $request, User $user): RedirectResponse
    {
        $subject = $user;

        if ($subject->role === UserRole::Admin && ! $this->otherActiveAdminsExist($subject)) {
            return back()->withErrors(['status' => __('admin.users.last_admin')]);
        }

        $this->audit->log('user.deleted', $subject, ['status' => $subject->status->value], [
            'status' => UserStatus::Deleted->value,
            'reason' => $request->reason(),
        ]);

        $subject->setAttribute('status', UserStatus::Deleted->value);
        $subject->save();
        $subject->delete();

        $this->destroySessionsOf($subject);

        return redirect()
            ->route('admin.users.index')
            ->with('status', __('admin.users.deleted'));
    }

    private function otherActiveAdminsExist(User $subject): bool
    {
        return User::query()
            ->where('role', UserRole::Admin->value)
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($subject->getKey())
            ->exists();
    }

    /** Sessions live in a table on shared hosting; ending one means deleting it. */
    private function destroySessionsOf(User $user): void
    {
        if ((string) config('session.driver') !== 'database') {
            return;
        }

        DB::table((string) config('session.table', 'user_sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }
}
