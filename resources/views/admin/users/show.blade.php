{{--
    Admin · one user's full profile, with the account-preview entry point.
    The system administrator's screen since D-117 — which took two sections
    off it: the account's own audit trail (it carries IP addresses and stays on
    the supervisor's audit screen) and seating the account in a cohort (the
    supervisor does that from the cohorts screen now).

    Every action button below is rendered from a server-computed permission flag
    ($user->canChangeRole, canSuspend, canPreview …). The flags exist to explain
    WHY an action is unavailable — the authorisation itself is re-checked by the
    policy on the target route, on every request (Article 5, BR-28).

    BR-32 (at least one active supervisor and one active system administrator)
    and the "no action on your own account" rule are therefore shown here as
    reasons, never relied on as protection.

    Four states: error · loading skeleton shaped like the profile · empty
    (an account with no enrolment yet) · normal.

    @see PRD §9.18, §4.4, §4.5 · BR-27, BR-28, BR-32, BR-33, BR-34, BR-35 · D-117
--}}
@extends('layouts.app')

@section('title', __('admin.users.actions.view_profile'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.users.index')" />
    @elseif (is_null($user))
        <x-ui.card class="dc--span">
            <x-ui.skeleton height="var(--s7)" width="var(--d-4)" />
            <x-ui.skeleton height="var(--s3)" width="var(--d-6)" class="u-mt-2" />
            <x-ui.skeleton height="var(--s3)" width="var(--d-5)" class="u-mt-2" />
            <x-ui.skeleton height="var(--touch-min)" width="100%" class="u-mt-4" />
        </x-ui.card>
    @else

        {{-- Identity --------------------------------------------------------------- --}}
        <x-ui.card class="dc--span" icon="user" :title="$user->name">
            <x-slot:action>
                <a href="{{ route('admin.users.index') }}">{{ __('app.back') }}</a>
            </x-slot:action>

            <div class="f2">
                <dl class="deflist">
                    <div><dt>{{ __('profile.full_name_ar') }}</dt><dd>{{ $user->fullNameAr }}</dd></div>
                    <div><dt>{{ __('profile.full_name_en') }}</dt><dd dir="ltr">{{ $user->fullNameEn }}</dd></div>
                    <div><dt>{{ __('admin.users.table.email') }}</dt><dd dir="ltr">{{ $user->email }}</dd></div>
                    <div><dt>{{ __('admin.users.table.phone') }}</dt><dd dir="ltr">{{ $user->phone }}</dd></div>
                </dl>

                <dl class="deflist">
                    <div>
                        <dt>{{ __('admin.users.table.role') }}</dt>
                        <dd><x-ui.pill :variant="$user->roleVariant">{{ $user->roleLabel }}</x-ui.pill></dd>
                    </div>
                    <div>
                        <dt>{{ __('admin.users.table.status') }}</dt>
                        <dd><x-ui.pill :variant="$user->statusVariant" :icon="$user->statusIcon">{{ $user->statusLabel }}</x-ui.pill></dd>
                    </div>
                    <div>
                        <dt>{{ __('admin.users.table.last_login') }}</dt>
                        <dd class="u-num">{{ $user->lastLoginAt ? \App\Support\Dates::dateTime($user->lastLoginAt) : '—' }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('admin.users.registered_at') }}</dt>
                        <dd class="u-num">{{ \App\Support\Dates::longDate($user->registeredAt) }}</dd>
                    </div>
                </dl>
            </div>
        </x-ui.card>

        {{-- Account preview — the strongest permission on the platform (PRD §4.5) --- --}}
        <x-ui.card class="dc--2 u-mt-4" icon="eye" :title="__('admin.preview.title')">
            @if ($user->canBePreviewed)
                <p>{{ __('admin.preview.confirm', ['name' => $user->name]) }}</p>

                {{-- A POST, like the route: a link's GET was answered 405 (D-117). --}}
                <form method="POST" action="{{ route('admin.users.preview', $user->id) }}" class="row__acts">
                    @csrf
                    <x-ui.button variant="secondary" icon="eye" type="submit">{{ __('admin.preview.start') }}</x-ui.button>
                </form>
            @else
                {{-- The reason is the one the server enforces (D-117) — never
                     "another administrator" for one's own or a suspended account. --}}
                <x-ui.empty-state variant="locked" icon="lock"
                    :title="$user->previewBlockedReason"
                    :description="__('admin.preview.blocked_body')" />
            @endif

            <h3 class="abrief__sub">{{ __('admin.preview.rules_title') }}</h3>
            <ul class="note__list" role="list">
                <li><x-ui.icon name="lock" /> {{ __('admin.preview.rules.write_blocked') }}</li>
                <li><x-ui.icon name="lock" /> {{ __('admin.preview.rules.no_traces') }}</li>
                <li><x-ui.icon name="lock" /> {{ __('admin.preview.rules.time_limit') }}</li>
                <li><x-ui.icon name="lock" /> {{ __('admin.preview.rules.audited') }}</li>
                <li><x-ui.icon name="lock" /> {{ __('admin.preview.rules.no_admin') }}</li>
            </ul>
        </x-ui.card>

        {{-- Account actions ---------------------------------------------------------- --}}
        <x-ui.card class="dc--2 u-mt-4" icon="shield" :title="__('admin.users.table.actions')">
            @unless ($user->canBeAdministered)
                <div class="note note--warn">
                    {{ $user->isSelf ? __('admin.users.self_action_blocked') : __('admin.users.last_admin_blocked') }}
                </div>
            @endunless

            <form method="POST" action="{{ route('admin.users.role', $user->id) }}">
                @csrf
                @method('PUT')

                <x-ui.select name="role" required :label="__('admin.users.actions.change_role')"
                    :options="$roleOptions" :value="old('role', $user->role)"
                    :disabled="! $user->canChangeRole" />

                <x-ui.textarea name="reason" rows="2" required minlength="10"
                    :label="__('admin.users.change_reason')"
                    :hint="__('admin.users.change_reason_hint')"
                    :disabled="! $user->canChangeRole" />

                <div class="row__acts">
                    <x-ui.button variant="primary" size="sm" type="submit"
                        :disabled="! $user->canChangeRole">{{ __('app.save_changes') }}</x-ui.button>
                </div>
            </form>

            <div class="row__acts">
                <form method="POST" action="{{ route('admin.users.status', $user->id) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="{{ $user->toggleStatusValue }}">
                    <x-ui.button variant="secondary" size="sm" type="submit"
                        :disabled="! $user->canChangeStatus">
                        {{ $user->isSuspended ? __('admin.users.actions.activate') : __('admin.users.actions.suspend') }}
                    </x-ui.button>
                </form>

                <form method="POST" action="{{ route('admin.users.resetPassword', $user->id) }}">
                    @csrf
                    <x-ui.button variant="secondary" size="sm" type="submit">{{ __('admin.users.actions.reset_password') }}</x-ui.button>
                </form>

                <form method="POST" action="{{ route('admin.users.logoutEverywhere', $user->id) }}">
                    @csrf
                    <x-ui.button variant="secondary" size="sm" type="submit">{{ __('admin.users.actions.logout_everywhere') }}</x-ui.button>
                </form>
            </div>

            <p class="footnote">{{ __('admin.users.constraints.audited') }}</p>
        </x-ui.card>

        {{-- Enrolments ---------------------------------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="users" :title="__('admin.users.enrollments_title')">
            @if ($user->enrollments->isEmpty())
                {{-- The text says what an empty list means for THIS role (D-117);
                     the action is one this screen's reader can take. --}}
                <x-ui.empty-state icon="users"
                    :title="__('admin.users.enrollments_empty_title')"
                    :description="$user->enrollmentsEmptyBody"
                    :action-label="__('admin.users.back_to_list')"
                    :action-href="route('admin.users.index')" />
            @endif

            @if ($user->enrollments->isNotEmpty())
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('admin.users.enrollments_title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('admin.cohorts.fields.name') }}</th>
                                <th scope="col">{{ __('admin.users.table.role') }}</th>
                                <th scope="col">{{ __('attendance.rate.label') }}</th>
                                <th scope="col">{{ __('grades.total.title') }}</th>
                                <th scope="col">{{ __('admin.users.table.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($user->enrollments as $enrollment)
                                <tr>
                                    <th scope="row">
                                        {{ $enrollment->cohortName }}
                                        <span class="u-muted">{{ $enrollment->programName }}</span>
                                    </th>
                                    <td>{{ $enrollment->roleLabel }}</td>
                                    <td class="u-num">
                                        {{ $enrollment->hasAttendance ? $enrollment->attendancePercent . '%' : '—' }}
                                    </td>
                                    <td class="u-num u-nowrap">
                                        {{ $enrollment->hasScore ? $enrollment->score . ' / ' . $enrollment->scoreMax : '—' }}
                                    </td>
                                    <td>
                                        <x-ui.pill :variant="$enrollment->statusVariant">{{ $enrollment->statusLabel }}</x-ui.pill>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif
@endsection
