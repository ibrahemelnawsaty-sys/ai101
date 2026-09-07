{{--
    Admin · cohorts.

    Dates, capacity, registration close time, pass score, minimum attendance
    rate and trainer assignment. The pass score and the minimum attendance rate
    are the two numbers BR-26 reads when it decides certificate eligibility, so
    they are edited here and nowhere else.

    Times are entered and displayed in Riyadh time and stored in UTC by the
    server; this template never converts anything itself.

    Four states: error · loading skeleton shaped like the table · empty · normal.

    @see PRD §9.18, §7.2 · BR-07, BR-26, BR-27, BR-31
--}}
@extends('layouts.app')

@section('title', __('admin.cohorts.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.cohorts.index')" />
    @else

        <div class="toolbar">
            <form method="GET" action="{{ route('admin.cohorts.index') }}" class="toolbar__filters">
                <x-ui.select name="program" :label="__('admin.programs.title')"
                    :options="$programOptions" :value="request('program')" />
                <x-ui.select name="status" :label="__('admin.cohorts.fields.status')"
                    :options="$statusOptions" :value="request('status')" />
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>
            <div class="toolbar__end">
                <x-ui.button variant="primary" size="sm"
                    :href="route('admin.cohorts.index', array_merge(request()->query(), ['edit' => 'new']))">{{ __('admin.cohorts.create') }}</x-ui.button>
            </div>
        </div>

        <x-ui.card class="dc--span u-mt-4" flush>
            @if (is_null($cohorts))
                <div class="tscroll">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 5; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-3)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                    <td><x-ui.skeleton height="var(--s2)" width="var(--d-1)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s18)" /></td>
                                    <td><x-ui.skeleton height="var(--s6)" width="var(--s18)" rounded="full" /></td>
                                    <td><x-ui.skeleton height="var(--s9)" width="var(--d-2)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($cohorts->isEmpty())
                <x-ui.empty-state icon="users"
                    :title="__('admin.cohorts.empty_title')"
                    :description="__('admin.cohorts.empty_body')"
                    :action-label="__('admin.cohorts.create')"
                    :action-href="route('admin.cohorts.index', array_merge(request()->query(), ['edit' => 'new']))" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('admin.cohorts.title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('admin.cohorts.fields.name') }}</th>
                                <th scope="col">{{ __('admin.cohorts.fields.starts_at') }}</th>
                                <th scope="col">{{ __('admin.cohorts.fields.capacity') }}</th>
                                <th scope="col">{{ __('admin.cohorts.fields.trainers') }}</th>
                                <th scope="col">{{ __('admin.cohorts.fields.status') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cohorts as $cohort)
                                <tr @class(['is-selected' => $cohort->id === ($editing->id ?? null)])>
                                    <th scope="row">
                                        {{ $cohort->name }}
                                        <span class="u-muted">{{ $cohort->programName }}</span>
                                    </th>
                                    <td class="u-num u-nowrap">
                                        {{ \App\Support\Dates::longDate($cohort->startsAt) }}
                                        —
                                        {{ \App\Support\Dates::longDate($cohort->endsAt) }}
                                    </td>
                                    <td>
                                        <x-ui.progress-bar :value="$cohort->seatsTaken" :max="$cohort->capacity"
                                            size="sm" :variant="$cohort->seatsVariant"
                                            :label="__('admin.cohorts.fields.capacity')" />
                                        <span class="u-num">{{ $cohort->seatsTaken }} / {{ $cohort->capacity }}</span>
                                    </td>
                                    <td>
                                        @if (count($cohort->trainerNames) === 0)
                                            <span class="u-muted">{{ __('app.none') }}</span>
                                        @else
                                            {{ implode(' · ', $cohort->trainerNames) }}
                                        @endif
                                    </td>
                                    <td>
                                        <x-ui.pill :variant="$cohort->statusVariant" :icon="$cohort->statusIcon">{{ $cohort->statusLabel }}</x-ui.pill>
                                    </td>
                                    <td class="u-nowrap">
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('admin.cohorts.index', array_merge(request()->query(), ['edit' => $cohort->id]))">{{ __('app.edit') }}</x-ui.button>
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('admin.cohorts.index', array_merge(request()->query(), ['trainers' => $cohort->id]))">{{ __('admin.cohorts.assign_trainer') }}</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$cohorts" />
            @endif
        </x-ui.card>

        {{-- Editor ------------------------------------------------------------------ --}}
        @if ($editing)
            <x-ui.card class="dc--span u-mt-4" icon="users"
                :title="$editing->exists ? __('app.edit') : __('admin.cohorts.create')">

                <form method="POST"
                    action="{{ $editing->exists ? route('admin.cohorts.update', $editing->id) : route('admin.cohorts.store') }}">
                    @csrf
                    @if ($editing->exists)
                        @method('PATCH')
                    @endif

                    <div class="f2">
                        <x-ui.input name="name" required :label="__('admin.cohorts.fields.name')"
                            :value="old('name', $editing->name)" />
                        <x-ui.select name="program_id" required :label="__('admin.programs.title')"
                            :options="$programOptions" :value="old('program_id', $editing->programId)" />
                    </div>

                    <div class="f2">
                        <x-ui.input name="starts_at" type="date" dir="ltr" required
                            :label="__('admin.cohorts.fields.starts_at')"
                            :value="old('starts_at', $editing->startsAtValue)" />
                        <x-ui.input name="ends_at" type="date" dir="ltr" required
                            :label="__('admin.cohorts.fields.ends_at')"
                            :value="old('ends_at', $editing->endsAtValue)" />
                        <x-ui.input name="registration_closes_at" type="datetime-local" dir="ltr"
                            :label="__('admin.cohorts.fields.registration_closes_at')"
                            :hint="__('app.riyadh_time_hint')"
                            :value="old('registration_closes_at', $editing->registrationClosesAtValue)" />
                    </div>

                    <div class="f2">
                        <x-ui.input name="capacity" type="number" min="1" step="1" required
                            :label="__('admin.cohorts.fields.capacity')"
                            :value="old('capacity', $editing->capacity)" />
                        <x-ui.input name="pass_score" type="number" min="0" max="100" step="1" required
                            :label="__('admin.cohorts.fields.pass_score')"
                            :hint="__('admin.cohorts.pass_score_hint')"
                            :value="old('pass_score', $editing->passScore)" />
                        <x-ui.input name="min_attendance_rate" type="number" min="0" max="100" step="1" required
                            :label="__('admin.cohorts.fields.min_attendance_rate')"
                            :hint="__('admin.cohorts.min_attendance_hint')"
                            :value="old('min_attendance_rate', $editing->minAttendanceRate)" />
                    </div>

                    <x-ui.checkbox name="requires_approval" value="1"
                        :checked="old('requires_approval', $editing->requiresApproval)"
                        :label="__('admin.cohorts.fields.requires_approval')" />

                    <x-ui.select name="status" required :label="__('admin.cohorts.fields.status')"
                        :options="$statusOptions" :value="old('status', $editing->status)" />

                    <p class="footnote">{{ __('certificates.both_required') }}</p>

                    <div class="row__acts">
                        <x-ui.button variant="primary" type="submit">{{ __('app.save_changes') }}</x-ui.button>
                        <x-ui.button variant="ghost"
                            :href="route('admin.cohorts.index', request()->except('edit'))">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        {{-- Trainer assignment --------------------------------------------------------- --}}
        @if ($assigning)
            <x-ui.card class="dc--span u-mt-4" icon="user"
                :title="__('admin.cohorts.assign_trainer')">

                <p><b>{{ $assigning->name }}</b> · {{ $assigning->programName }}</p>

                @if (count($assigning->trainers) === 0)
                    <x-ui.empty-state icon="user"
                        :title="__('admin.cohorts.trainers_empty_title')"
                        :description="__('admin.cohorts.trainers_empty_body')" />
                @else
                    <ul class="reslist" role="list">
                        @foreach ($assigning->trainers as $trainer)
                            <li class="row">
                                <div class="row__m">
                                    <b>{{ $trainer->name }}</b>
                                    <span dir="ltr">{{ $trainer->email }}</span>
                                </div>
                                <div class="row__e">
                                    <form method="POST"
                                        action="{{ route('admin.cohorts.trainers.detach', [$assigning->id, $trainer->id]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button variant="secondary" size="sm" type="submit">{{ __('admin.cohorts.remove_trainer') }}</x-ui.button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <form method="POST" action="{{ route('admin.cohorts.trainers.attach', $assigning->id) }}">
                    @csrf

                    <x-ui.input name="email" type="email" dir="ltr" required
                        :label="__('admin.cohorts.trainer_email')"
                        :hint="__('admin.cohorts.trainer_email_hint')"
                        :value="old('email')" />

                    <div class="row__acts">
                        <x-ui.button variant="primary" type="submit">{{ __('admin.cohorts.assign_trainer') }}</x-ui.button>
                        <x-ui.button variant="ghost"
                            :href="route('admin.cohorts.index', request()->except('trainers'))">{{ __('app.close') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif
    @endif
@endsection
