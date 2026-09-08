{{--
    Admin · registration requests.

    Only cohorts whose `requires_approval` flag is on produce requests here.
    Rejection requires a written reason, because the reason is what the applicant
    receives by email; approval and rejection are both written to the audit log
    before they take effect (Article 8).

    Four states: error · loading skeleton shaped like the table · empty · normal.

    @see PRD §9.18, §9.2.3 · BR-27, BR-28, BR-31 · D-11 (open: open registration or screening;
         D-05 is the storage ceiling, not this)
--}}
@extends('layouts.app')

@section('title', __('admin.registrations.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.registrations.index')" />
    @else

        <div class="toolbar">
            <form method="GET" action="{{ route('admin.registrations.index') }}" class="toolbar__filters">
                <x-ui.search-input name="q" :value="request('q')"
                    :placeholder="__('admin.users.filters.search_placeholder')" />
                <x-ui.select name="cohort" :label="__('admin.cohorts.title')"
                    :options="$cohortOptions" :value="request('cohort')" />
                <x-ui.select name="state" :label="__('admin.users.table.status')"
                    :options="$stateOptions" :value="request('state')" />
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>
            <div class="toolbar__end">
                <x-ui.button variant="secondary" size="sm"
                    :href="route('admin.registrations.export', request()->query())">{{ __('app.export_excel') }}</x-ui.button>
            </div>
        </div>

        <x-ui.card class="dc--span u-mt-4" flush>
            @if (is_null($requests))
                <div class="tscroll">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 6; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-3)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-1)" /></td>
                                    <td><x-ui.skeleton height="var(--s6)" width="var(--s18)" rounded="full" /></td>
                                    <td><x-ui.skeleton height="var(--s9)" width="var(--d-1)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($requests->isEmpty())
                <x-ui.empty-state icon="check"
                    :title="__('admin.registrations.empty_title')"
                    :description="__('admin.registrations.empty_body')"
                    :action-label="request()->hasAny(['q', 'cohort', 'state']) ? __('app.clear_filters') : null"
                    :action-href="request()->hasAny(['q', 'cohort', 'state']) ? route('admin.registrations.index') : null" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('admin.registrations.title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('admin.users.table.user') }}</th>
                                <th scope="col">{{ __('admin.users.table.email') }}</th>
                                <th scope="col">{{ __('admin.cohorts.fields.name') }}</th>
                                <th scope="col">{{ __('admin.registrations.requested_at') }}</th>
                                <th scope="col">{{ __('admin.users.table.status') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($requests as $request)
                                <tr @class(['is-selected' => $request->id === ($reviewing->id ?? null)])>
                                    <th scope="row">
                                        <span class="cellpair">
                                            <x-ui.avatar size="sm" :name="$request->name" />
                                            {{ $request->name }}
                                        </span>
                                    </th>
                                    <td dir="ltr" class="u-ltr">{{ $request->email }}</td>
                                    <td>{{ $request->cohortName }}</td>
                                    <td class="u-num u-nowrap">{{ \App\Support\Dates::dateTime($request->requestedAt) }}</td>
                                    <td>
                                        <x-ui.pill :variant="$request->stateVariant" :icon="$request->stateIcon">{{ $request->stateLabel }}</x-ui.pill>
                                    </td>
                                    <td class="u-nowrap">
                                        @if ($request->isPending)
                                            <x-ui.button variant="primary" size="sm"
                                                :href="route('admin.registrations.index', array_merge(request()->query(), ['review' => $request->id]))">{{ __('app.view_details') }}</x-ui.button>
                                        @else
                                            <span class="u-muted">{{ $request->decidedByLabel }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$requests" />
            @endif
        </x-ui.card>

        {{-- Review one request --------------------------------------------------------- --}}
        @if ($reviewing)
            <x-ui.card class="dc--span u-mt-4" icon="file"
                :title="__('admin.registrations.review_title', ['name' => $reviewing->name])">

                <div class="f2">
                    <dl class="deflist">
                        <div><dt>{{ __('profile.full_name_ar') }}</dt><dd>{{ $reviewing->fullNameAr }}</dd></div>
                        <div><dt>{{ __('profile.full_name_en') }}</dt><dd dir="ltr">{{ $reviewing->fullNameEn }}</dd></div>
                        <div><dt>{{ __('admin.users.table.email') }}</dt><dd dir="ltr">{{ $reviewing->email }}</dd></div>
                        <div><dt>{{ __('admin.users.table.phone') }}</dt><dd dir="ltr">{{ $reviewing->phone }}</dd></div>
                    </dl>

                    <dl class="deflist">
                        <div><dt>{{ __('admin.cohorts.fields.name') }}</dt><dd>{{ $reviewing->cohortName }}</dd></div>
                        <div>
                            <dt>{{ __('admin.cohorts.fields.capacity') }}</dt>
                            <dd class="u-num">{{ __('admin.cohorts.seats_taken', ['taken' => $reviewing->seatsTaken, 'capacity' => $reviewing->capacity]) }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('admin.registrations.requested_at') }}</dt>
                            <dd class="u-num">{{ \App\Support\Dates::dateTime($reviewing->requestedAt) }}</dd>
                        </div>
                    </dl>
                </div>

                @unless ($reviewing->hasFreeSeat)
                    <div class="note note--warn">
                        {{ __('admin.registrations.no_free_seat') }}
                    </div>
                @endunless

                <div class="f2">
                    <form method="POST" action="{{ route('admin.registrations.approve', $reviewing->id) }}">
                        @csrf
                        @method('PUT')
                        <p>{{ __('admin.registrations.approve_hint') }}</p>
                        <div class="row__acts">
                            <x-ui.button variant="primary" type="submit"
                                :disabled="! $reviewing->hasFreeSeat">{{ __('admin.registrations.approve') }}</x-ui.button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('admin.registrations.reject', $reviewing->id) }}">
                        @csrf
                        @method('PUT')

                        <x-ui.textarea name="rejection_reason" rows="3" required minlength="10"
                            :label="__('admin.registrations.reject_reason')"
                            :hint="__('admin.registrations.reject_reason_hint')"
                            :value="old('rejection_reason')" />

                        <div class="row__acts">
                            <x-ui.button variant="danger" type="submit">{{ __('admin.registrations.reject') }}</x-ui.button>
                            <x-ui.button variant="ghost"
                                :href="route('admin.registrations.index', request()->except('review'))">{{ __('app.cancel') }}</x-ui.button>
                        </div>
                    </form>
                </div>
            </x-ui.card>
        @endif
    @endif
@endsection
