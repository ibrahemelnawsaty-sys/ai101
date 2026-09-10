{{--
    Admin · user management. Ported from the approved screen `scr-ausers`.

    The account-preview button is rendered only when the SERVER says it may be
    previewed. An administrator account can never be previewed (BR-35), and the
    row shows the reason instead of a disabled button, so the rule is visible
    rather than merely unavailable. Hiding the button is not the protection —
    the preview route re-checks the same rule on every request (Article 5).

    Four states: error · loading skeleton shaped like the table · empty · normal.

    @see PRD §9.18, §4.5 · BR-27, BR-28, BR-32, BR-33, BR-34, BR-35
--}}
@extends('layouts.app')

@section('title', __('admin.users.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.users.index')" />
    @else

        {{-- Counters by role ------------------------------------------------------ --}}
        <div class="dgrid dgrid--stats">
            @if (is_null($counts))
                @for ($i = 0; $i < 4; $i++)
                    <x-ui.card>
                        <x-ui.skeleton height="var(--s7)" width="var(--s13)" />
                        <x-ui.skeleton height="var(--s3)" width="66%" class="u-mt-2" />
                    </x-ui.card>
                @endfor
            @else
                <x-ui.stat-card :value="$counts->participants" :label="__('enums.user_role.participant')" />
                <x-ui.stat-card variant="teal" :value="$counts->trainers" :label="__('enums.user_role.trainer')" />
                <x-ui.stat-card variant="brand" :value="$counts->admins" :label="__('enums.user_role.admin')" />
                <x-ui.stat-card :variant="$counts->pending > 0 ? 'warning' : 'success'"
                    :value="$counts->pending" :label="__('enums.user_status.pending')" />
            @endif
        </div>

        {{-- Table ------------------------------------------------------------------ --}}
        <x-ui.card class="dc--span u-mt-4" flush>
            <div class="tablebar">
                <form method="GET" action="{{ route('admin.users.index') }}" class="toolbar__filters">
                    <x-ui.search-input name="q" :value="request('q')"
                        :placeholder="__('admin.users.filters.search_placeholder')" />
                    <x-ui.select name="role" :label="__('admin.users.table.role')"
                        :options="$roleOptions" :value="request('role')" />
                    <x-ui.select name="status" :label="__('admin.users.table.status')"
                        :options="$statusOptions" :value="request('status')" />
                    <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
                </form>
                <div class="toolbar__end">
                    <x-ui.button variant="secondary" size="sm"
                        :href="route('admin.users.export', request()->query())">{{ __('app.export_excel') }}</x-ui.button>
                    <x-ui.button variant="secondary" size="sm"
                        :href="route('admin.users.import')">{{ __('admin.users.import.button') }}</x-ui.button>
                    <x-ui.button variant="primary" size="sm"
                        :href="route('admin.users.create')">{{ __('admin.users.create') }}</x-ui.button>
                </div>
            </div>

            @if (is_null($users))
                <div class="tscroll">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 8; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-3)" /></td>
                                    <td><x-ui.skeleton height="var(--s6)" width="var(--s17)" rounded="full" /></td>
                                    <td><x-ui.skeleton height="var(--s6)" width="var(--s17)" rounded="full" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-1)" /></td>
                                    <td><x-ui.skeleton height="var(--s9)" width="var(--d-3)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($users->isEmpty())
                <x-ui.empty-state icon="user"
                    :title="__('admin.users.empty_title')"
                    :description="__('admin.users.empty_body')"
                    :action-label="request()->hasAny(['q', 'role', 'status']) ? __('app.clear_filters') : null"
                    :action-href="request()->hasAny(['q', 'role', 'status']) ? route('admin.users.index') : null" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('admin.users.title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('admin.users.table.user') }}</th>
                                <th scope="col">{{ __('admin.users.table.email') }}</th>
                                <th scope="col">{{ __('admin.users.table.role') }}</th>
                                <th scope="col">{{ __('admin.users.table.status') }}</th>
                                <th scope="col">{{ __('admin.users.table.last_login') }}</th>
                                <th scope="col"><span class="sr">{{ __('admin.users.table.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $user)
                                <tr>
                                    <th scope="row">
                                        <span class="cellpair">
                                            <x-ui.avatar size="sm" :name="$user->name" />
                                            {{ $user->name }}
                                        </span>
                                    </th>
                                    <td dir="ltr" class="u-ltr">{{ $user->email }}</td>
                                    <td>
                                        <x-ui.pill :variant="$user->roleVariant">{{ $user->roleLabel }}</x-ui.pill>
                                    </td>
                                    <td>
                                        <x-ui.pill :variant="$user->statusVariant" :icon="$user->statusIcon">{{ $user->statusLabel }}</x-ui.pill>
                                    </td>
                                    <td class="u-num u-nowrap">
                                        {{ $user->lastLoginAt ? \App\Support\Dates::dateTime($user->lastLoginAt) : '—' }}
                                    </td>
                                    <td class="u-nowrap">
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('admin.users.show', $user->id)">{{ __('admin.users.actions.view_profile') }}</x-ui.button>

                                        @if ($user->awaitingVerification)
                                            <form method="POST" action="{{ route('admin.users.resendVerification', $user->id) }}">
                                                @csrf
                                                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('admin.users.actions.resend_verification') }}</x-ui.button>
                                            </form>
                                        @elseif ($user->canBePreviewed)
                                            <x-ui.button variant="secondary" size="sm" icon="eye"
                                                :href="route('admin.users.preview', $user->id)">{{ __('admin.users.actions.preview') }}</x-ui.button>
                                        @else
                                            <span class="hint">
                                                <x-ui.icon name="lock" />
                                                {{ __('admin.preview.admin_blocked') }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$users" />
            @endif
        </x-ui.card>

        {{-- What the server enforces regardless of this screen ---------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="shield" :title="__('admin.users.constraints_title')">
            <ul class="note__list" role="list">
                <li>
                    <x-ui.icon name="lock" />
                    {{ __('admin.users.constraints.no_self_delete') }}
                </li>
                <li>
                    <x-ui.icon name="lock" />
                    {{ __('admin.users.constraints.last_admin') }}
                </li>
                <li>
                    <x-ui.icon name="lock" />
                    {{ __('admin.users.constraints.soft_delete') }}
                </li>
                <li>
                    <x-ui.icon name="lock" />
                    {{ __('admin.users.constraints.audited') }}
                </li>
            </ul>
        </x-ui.card>
    @endif
@endsection
