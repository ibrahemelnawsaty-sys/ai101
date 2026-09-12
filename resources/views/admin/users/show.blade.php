{{--
    Admin · one user's full profile, with the account-preview entry point.

    Every action button below is rendered from a server-computed permission flag
    ($user->canChangeRole, canSuspend, canPreview …). The flags exist to explain
    WHY an action is unavailable — the authorisation itself is re-checked by the
    policy on the target route, on every request (Article 5, BR-28).

    BR-32 (at least one active administrator) and the "no action on your own
    account" rule are therefore shown here as reasons, never relied on as
    protection.

    Four states: error · loading skeleton shaped like the profile · empty
    (an account with no enrolment yet) · normal.

    @see PRD §9.18, §4.4, §4.5 · BR-27, BR-28, BR-32, BR-33, BR-34, BR-35
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

                <div class="row__acts">
                    <x-ui.button variant="secondary" icon="eye"
                        :href="route('admin.users.preview', $user->id)">{{ __('admin.preview.start') }}</x-ui.button>
                </div>
            @else
                <x-ui.empty-state variant="locked" icon="lock"
                    :title="__('admin.preview.admin_blocked')"
                    :description="__('admin.preview.rules.no_admin')" />
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
            @if ($user->enrollments->isEmpty() && $user->attachesFromCohorts)
                <x-ui.empty-state icon="users"
                    :title="__('admin.users.enrollments_empty_title')"
                    :description="__('admin.users.enrollments_empty_body')"
                    :action-label="__('admin.cohorts.title')"
                    :action-href="route('admin.cohorts.index')" />
            @elseif ($user->enrollments->isEmpty())
                <x-ui.empty-state icon="users"
                    :title="__('admin.users.enrollments_empty_title')"
                    :description="__('admin.users.enrollments_empty_participant_body')" />
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

            @if ($user->canEnroll)
                @if ($enrollOptions === [])
                    <p class="footnote">{{ __('admin.users.enroll_none') }}</p>
                @else
                    <form method="POST" action="{{ route('admin.users.enroll', $user->id) }}" class="u-mt-4">
                        @csrf
                        <x-ui.select name="cohort_id" required :label="__('admin.users.enroll_label')"
                            :hint="__('admin.users.enroll_hint')"
                            :options="$enrollOptions" :value="old('cohort_id')" />

                        <div class="row__acts">
                            <x-ui.button variant="primary" size="sm" type="submit">{{ __('admin.users.enroll_submit') }}</x-ui.button>
                        </div>
                    </form>
                @endif
            @endif
        </x-ui.card>

        {{-- Recent audit entries for this account -------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="shield" :title="__('admin.audit.title')">
            <x-slot:action>
                <a href="{{ route('admin.audit.index', ['entity' => $user->id]) }}">{{ __('app.view_all') }}</a>
            </x-slot:action>

            @if ($user->auditEntries->isEmpty())
                <x-ui.empty-state icon="shield"
                    :title="__('admin.audit.empty_title')"
                    :description="__('admin.audit.empty_body')" />
            @else
                @foreach ($user->auditEntries as $entry)
                    <div class="row">
                        <div class="row__m">
                            <b>{{ $entry->actionLabel }}</b>
                            <span>
                                {{ $entry->actorName }} ·
                                <span class="u-num">{{ \App\Support\Dates::dateTime($entry->at) }}</span>
                            </span>
                        </div>
                        <div class="row__e">
                            <span class="u-num u-ltr" dir="ltr">{{ $entry->ipAddress }}</span>
                        </div>
                    </div>
                @endforeach

                <p class="footnote">{{ __('admin.audit.immutable_note') }}</p>
            @endif
        </x-ui.card>
    @endif
@endsection
