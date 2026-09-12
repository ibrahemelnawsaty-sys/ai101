<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Events\PasswordChanged;
use App\Http\Controllers\Auth\Concerns\InvalidatesOtherSessions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\ChangePasswordRequest;
use App\Http\Requests\Participant\UpdateNotificationPreferencesRequest;
use App\Http\Requests\Participant\UpdateProfileRequest;
use App\Models\NotificationPreference;
use App\Models\Profile;
use App\Models\User;
use App\Presenters\Participant\DevicePresenter;
use App\Presenters\Participant\PreferencePresenter;
use App\Presenters\Participant\ProfilePresenter;
use App\Services\Audit\AuditLogger;
use App\Services\Permissions\RoleResolver;
use App\Services\Time\Clock;
use App\Support\ImpersonationContext;
use App\Support\NotificationTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * My account (PRD §9.4).
 *
 * Three rules meet on this screen:
 *   BR-29 — changing the password ends every other session, for real, on the
 *           server; the browser that made the change stays signed in.
 *   BR-33 — while an account preview is running every form here is refused, by
 *           the middleware and again by the policy.
 *   The e-mail address is not editable here: changing it re-opens verification
 *   and belongs to its own flow.
 *
 * @see BR-22, BR-29, BR-33 · PRD §9.4, §9.16 · CONSTITUTION Art. 22, Art. 24
 */
final class ProfileController extends Controller
{
    use InvalidatesOtherSessions;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RoleResolver $roles,
    ) {}

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('participant.profile', [
            'profile' => ProfilePresenter::from($user, $user->profile()->first()),
            'preferences' => $this->preferences($user),
            'sessions' => $this->activeSessions($user, $request)
                ->map(static fn (object $row): DevicePresenter => DevicePresenter::from($row)),
            'isImpersonating' => ImpersonationContext::isActive(),
            'errorState' => null,
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $profile = Profile::query()->where('user_id', $user->getKey())->firstOrFail();

        $before = $this->audit->snapshot($profile, ['phone']);

        $profile->fill($request->profileAttributes());

        $this->audit->log(
            action: 'profile.updated',
            entity: $profile,
            before: $before,
            after: $this->audit->snapshot($profile, ['phone']),
            actor: $user,
        );

        $profile->save();

        return back()->with('status', __('profile.saved'));
    }

    /**
     * BR-29 — the new password and the end of every other session are one act,
     * not two: they happen in the same request, and the trail records it.
     */
    public function password(ChangePasswordRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $at = Clock::now();

        DB::transaction(function () use ($user, $request): void {
            $user->setAttribute('password_hash', (string) $request->validated('password'));

            $this->audit->log('account.password_changed', $user, null, null, $user);

            $user->save();
        });

        $this->invalidateOtherSessions($request, $user);

        PasswordChanged::dispatch($user, $at, 'profile');

        return back()->with('status', __('profile.password_changed'));
    }

    /**
     * One row for every type this account receives (PRD §9.16.1), each showing
     * the stored choice or — with no row — on, which is what every sender does
     * for a missing row (D-66).
     *
     * It listed the stored rows only, and only seeded demo accounts ever had
     * any: every real, invited trainee opened an empty table and could switch
     * nothing. Rows of a type the catalogue does not know are left out, so the
     * table can never post a key its own request refuses (D-78).
     *
     * @return \Illuminate\Support\Collection<int, PreferencePresenter>
     */
    private function preferences(User $user): \Illuminate\Support\Collection
    {
        $stored = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->get()
            ->keyBy(static fn (NotificationPreference $row): string => (string) $row->getAttribute('type'));

        return collect(NotificationTypes::forRole($this->roles->shellRole($user)))
            ->map(static function (string $type) use ($stored, $user): PreferencePresenter {
                $row = $stored->get($type) ?? new NotificationPreference([
                    'user_id' => $user->getKey(),
                    'type' => $type,
                    'in_app_enabled' => true,
                    'email_enabled' => true,
                ]);

                return PreferencePresenter::from($row);
            })
            ->values();
    }

    public function notifications(UpdateNotificationPreferencesRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // One row per event type, each channel from its own switch. The old
        // loop wrote a single value into BOTH columns, so switching e-mail off
        // silenced the bell as well — and it iterated keys the form never sent,
        // so it never ran at all (D-65).
        foreach ($request->preferences() as $type => $channels) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'type' => $type],
                [
                    'in_app_enabled' => $channels['platform'],
                    'email_enabled' => $channels['email'],
                ],
            );
        }

        return back()->with('status', __('profile.preferences_saved'));
    }

    /**
     * "Sign out of every device" (PRD §9.3.2). The current session survives so
     * the person is not thrown out of the page they are standing on.
     */
    public function destroySessions(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('logoutEverywhere', $user);

        $destroyed = $this->invalidateOtherSessions($request, $user);

        $this->audit->log('account.sessions_revoked', $user, null, ['count' => $destroyed], $user);

        return back()->with('status', __('profile.sessions_revoked'));
    }

    /**
     * The account's live sessions, for the "signed in on" list. Only the facts
     * the person needs to recognise a device: never the session payload.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function activeSessions(User $user, Request $request)
    {
        if ((string) config('session.driver') !== 'database') {
            return collect();
        }

        $currentId = $request->hasSession() ? $request->session()->getId() : null;

        return DB::table((string) config('session.table', 'user_sessions'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->limit(20)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(static function (object $row) use ($currentId): object {
                $row->is_current = $currentId !== null && $row->id === $currentId;
                unset($row->id);

                return $row;
            });
    }
}
