{{--
    Admin console home.

    The six statistic cards required by PRD §9.18, each with its OWN skeleton
    and its OWN empty state, followed by the queues that actually need the
    administrator's attention (pending registrations, ungraded submissions,
    participants who have met the certificate conditions but hold no
    certificate yet).

    Every figure is computed on the server. Nothing here is a link to an action
    that the target route does not itself re-authorise (Article 5).

    @see PRD §9.18 · BR-26, BR-27, BR-28, BR-31, BR-32
--}}
@extends('layouts.app')

@section('title', __('admin.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('dashboard')" />
    @else

        {{-- The six required figures (PRD §9.18) --------------------------------- --}}
        <div class="dgrid dgrid--stats">
            @if (is_null($stats))
                @for ($i = 0; $i < 6; $i++)
                    <x-ui.card>
                        <x-ui.skeleton height="var(--s7)" width="var(--s15)" />
                        <x-ui.skeleton height="var(--s3)" width="74%" class="u-mt-2" />
                    </x-ui.card>
                @endfor
            @else
                <x-ui.stat-card :value="$stats->totalRegistered"
                    :label="__('admin.stats.total_registered')" />
                <x-ui.stat-card variant="brand" :value="$stats->activeUsers"
                    :label="__('admin.stats.active_users')" />
                <x-ui.stat-card :variant="$stats->attendanceVariant"
                    :value="$stats->averageAttendance . '%'"
                    :label="__('admin.stats.average_attendance')" />
                <x-ui.stat-card :value="$stats->averageScore . ' / ' . $stats->scoreMax"
                    :label="__('admin.stats.average_score')" />
                <x-ui.stat-card :variant="$stats->ungradedSubmissions > 0 ? 'warning' : 'success'"
                    :value="$stats->ungradedSubmissions"
                    :label="__('admin.stats.ungraded_submissions')" />
                <x-ui.stat-card variant="brand" :value="$stats->certificatesIssued"
                    :label="__('admin.stats.certificates_issued')" />
            @endif
        </div>

        {{-- Registration requests waiting for a decision -------------------------- --}}
        <x-ui.card class="dc--2 u-mt-4" icon="file" :title="__('admin.registrations.title')">
            <x-slot:action>
                <a href="{{ route('admin.registrations.index') }}">{{ __('app.view_all') }}</a>
            </x-slot:action>

            @if (is_null($pendingRegistrations))
                @for ($i = 0; $i < 3; $i++)
                    <div class="row row--sk">
                        <div class="row__m">
                            <x-ui.skeleton height="var(--s4)" width="58%" />
                            <x-ui.skeleton height="var(--s3)" width="40%" class="u-mt-1" />
                        </div>
                        <x-ui.skeleton height="var(--s9)" width="var(--s22)" />
                    </div>
                @endfor
            @elseif ($pendingRegistrations->isEmpty())
                <x-ui.empty-state icon="check"
                    :title="__('admin.registrations.empty_title')"
                    :description="__('admin.registrations.empty_body')" />
            @else
                @foreach ($pendingRegistrations as $request)
                    <div class="row">
                        <div class="row__m">
                            <b>{{ $request->name }}</b>
                            <span>
                                {{ $request->cohortName }} ·
                                <span class="u-num">{{ \App\Support\Dates::relative($request->requestedAt) }}</span>
                            </span>
                        </div>
                        <div class="row__e">
                            <x-ui.button variant="secondary" size="sm"
                                :href="route('admin.registrations.index', ['review' => $request->id])">{{ __('app.view_details') }}</x-ui.button>
                        </div>
                    </div>
                @endforeach
            @endif
        </x-ui.card>

        {{-- Submissions still without a grade -------------------------------------- --}}
        <x-ui.card class="dc--2 u-mt-4" icon="badge" :title="__('admin.stats.ungraded_submissions')">
            @if (is_null($ungraded))
                @for ($i = 0; $i < 3; $i++)
                    <div class="row row--sk">
                        <div class="row__m">
                            <x-ui.skeleton height="var(--s4)" width="62%" />
                            <x-ui.skeleton height="var(--s3)" width="44%" class="u-mt-1" />
                        </div>
                    </div>
                @endfor
            @elseif ($ungraded->isEmpty())
                <x-ui.empty-state variant="success" icon="check"
                    :title="__('admin.ungraded_empty_title')"
                    :description="__('admin.ungraded_empty_body')" />
            @else
                @foreach ($ungraded as $group)
                    <div class="row">
                        <div class="row__m">
                            <b>{{ $group->cohortName }}</b>
                            <span>{{ trans_choice('app.counts.submissions', $group->count, ['count' => $group->count]) }}</span>
                        </div>
                        <div class="row__e">
                            <x-ui.pill variant="warning" icon="clock">
                                <span class="u-num">{{ $group->count }}</span>
                            </x-ui.pill>
                        </div>
                    </div>
                @endforeach
            @endif
        </x-ui.card>

        {{-- Certificates ready to be issued (BR-26) --------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="badge" :title="__('certificates.admin.eligible_list')">
            <x-slot:action>
                <a href="{{ route('admin.certificates.index') }}">{{ __('certificates.admin.title') }}</a>
            </x-slot:action>

            @if (is_null($readyForCertificate))
                @for ($i = 0; $i < 3; $i++)
                    <div class="row row--sk">
                        <div class="row__m">
                            <x-ui.skeleton height="var(--s4)" width="52%" />
                            <x-ui.skeleton height="var(--s3)" width="38%" class="u-mt-1" />
                        </div>
                        <x-ui.skeleton height="var(--s9)" width="var(--d-1)" />
                    </div>
                @endfor
            @elseif ($readyForCertificate->isEmpty())
                <x-ui.empty-state icon="badge"
                    :title="__('certificates.admin.empty_title')"
                    :description="__('certificates.admin.empty_body')" />
            @else
                @foreach ($readyForCertificate as $candidate)
                    <div class="row">
                        <div class="row__m">
                            <b>{{ $candidate->name }}</b>
                            <span>
                                {{ $candidate->cohortName }} ·
                                <span class="u-num">{{ $candidate->attendancePercent }}%</span> ·
                                <span class="u-num">{{ $candidate->score }} / {{ $candidate->scoreMax }}</span>
                            </span>
                        </div>
                        <div class="row__e">
                            <x-ui.button variant="primary" size="sm"
                                :href="route('admin.certificates.index', ['candidate' => $candidate->id])">{{ __('certificates.admin.issue_one') }}</x-ui.button>
                        </div>
                    </div>
                @endforeach

                <p class="footnote">{{ __('certificates.both_required') }}</p>
            @endif
        </x-ui.card>
    @endif
@endsection
