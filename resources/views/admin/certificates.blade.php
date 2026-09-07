{{--
    Admin · certificate issuing (individual and bulk).

    BR-26: attendance AND score must both be met; meeting one never compensates
    for the other. The eligibility decision and the Arabic reasons both come from
    CertificateEligibility — this template only prints them, and it prints EVERY
    reason for every ineligible person, untruncated, so the administrator can see
    exactly what is missing before deciding to override.

    A manual override is possible but never silent: it requires a written reason
    and is written to the audit log.

    Four states: error · loading skeleton shaped like each list · empty · normal.

    @see PRD §9.17, §9.18 · BR-24, BR-25, BR-26, BR-27, BR-28
--}}
@extends('layouts.app')

@section('title', __('certificates.admin.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.certificates.index')" />
    @else

        <div class="toolbar">
            <form method="GET" action="{{ route('admin.certificates.index') }}" class="toolbar__filters">
                <x-ui.select name="cohort" :label="__('admin.cohorts.title')"
                    :options="$cohortOptions" :value="request('cohort')" />
                <x-ui.search-input name="q" :value="request('q')"
                    :placeholder="__('admin.users.filters.search_placeholder')" />
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>
            <div class="toolbar__end">
                <x-ui.button variant="secondary" size="sm"
                    :href="route('admin.certificates.export', request()->query())">{{ __('app.export_excel') }}</x-ui.button>
            </div>
        </div>

        {{-- Counters ---------------------------------------------------------------- --}}
        <div class="dgrid dgrid--stats u-mt-4">
            @if (is_null($counts))
                @for ($i = 0; $i < 4; $i++)
                    <x-ui.card>
                        <x-ui.skeleton height="var(--s7)" width="var(--s13)" />
                        <x-ui.skeleton height="var(--s3)" width="70%" class="u-mt-2" />
                    </x-ui.card>
                @endfor
            @else
                <x-ui.stat-card variant="success" :value="$counts->eligible"
                    :label="__('certificates.admin.eligible_list')" />
                <x-ui.stat-card variant="brand" :value="$counts->issued"
                    :label="__('admin.stats.certificates_issued')" />
                <x-ui.stat-card variant="warning" :value="$counts->notEligible"
                    :label="__('certificates.admin.not_eligible_list')" />
                <x-ui.stat-card variant="error" :value="$counts->revoked"
                    :label="__('certificates.admin.stat_revoked')" />
            @endif
        </div>

        {{-- Eligible, not yet issued -------------------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="badge" :title="__('certificates.admin.eligible_list')">
            @if (is_null($eligible))
                @for ($i = 0; $i < 4; $i++)
                    <div class="row row--sk">
                        <x-ui.skeleton height="var(--s5)" width="var(--s5)" rounded="md" />
                        <div class="row__m">
                            <x-ui.skeleton height="var(--s4)" width="56%" />
                            <x-ui.skeleton height="var(--s3)" width="42%" class="u-mt-1" />
                        </div>
                        <x-ui.skeleton height="var(--s9)" width="var(--d-1)" />
                    </div>
                @endfor
            @elseif ($eligible->isEmpty())
                <x-ui.empty-state icon="badge"
                    :title="__('certificates.admin.empty_title')"
                    :description="__('certificates.admin.empty_body')" />
            @else
                <form method="POST" action="{{ route('admin.certificates.issueBulk') }}">
                    @csrf

                    <div class="tscroll">
                        <table class="atable">
                            <caption class="sr">{{ __('certificates.admin.eligible_list') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col"><span class="sr">{{ __('certificates.admin.select_all') }}</span></th>
                                    <th scope="col">{{ __('trainer.col_participant') }}</th>
                                    <th scope="col">{{ __('attendance.rate.label') }}</th>
                                    <th scope="col">{{ __('certificates.final_score') }}</th>
                                    <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($eligible as $candidate)
                                    <tr>
                                        <td>
                                            <x-ui.checkbox name="candidates[]" :value="$candidate->id"
                                                :label="__('certificates.admin.select_candidate', ['name' => $candidate->name])"
                                                label-hidden />
                                        </td>
                                        <th scope="row">
                                            <span class="cellpair">
                                                <x-ui.avatar size="sm" :name="$candidate->name" />
                                                {{ $candidate->name }}
                                            </span>
                                        </th>
                                        <td>
                                            <x-ui.pill variant="success" icon="check">
                                                <span class="u-num">{{ $candidate->attendancePercent }}%</span>
                                            </x-ui.pill>
                                        </td>
                                        <td class="u-num u-nowrap">{{ $candidate->score }} / {{ $candidate->scoreMax }}</td>
                                        <td class="u-nowrap">
                                            <x-ui.button variant="primary" size="sm" type="submit"
                                                name="single" :value="$candidate->id">{{ __('certificates.admin.issue_one') }}</x-ui.button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="row__acts">
                        <x-ui.button variant="primary" type="submit">{{ __('certificates.admin.issue_all_eligible') }}</x-ui.button>
                        <p class="hint">
                            <x-ui.icon name="badge" />
                            {{ __('certificates.admin.serial_format_hint') }}
                        </p>
                    </div>
                </form>
            @endif
        </x-ui.card>

        {{-- Not eligible, and exactly why (BR-26) -------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="warn" :title="__('certificates.admin.not_eligible_list')">
            @if (is_null($notEligible))
                @for ($i = 0; $i < 3; $i++)
                    <div class="row row--sk">
                        <div class="row__m">
                            <x-ui.skeleton height="var(--s4)" width="48%" />
                            <x-ui.skeleton height="var(--s3)" width="80%" class="u-mt-1" />
                            <x-ui.skeleton height="var(--s3)" width="66%" class="u-mt-1" />
                        </div>
                        <x-ui.skeleton height="var(--s9)" width="var(--d-1)" />
                    </div>
                @endfor
            @elseif ($notEligible->isEmpty())
                <x-ui.empty-state variant="success" icon="check"
                    :title="__('certificates.admin.none_ineligible_title')"
                    :description="__('certificates.both_required')" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('certificates.admin.not_eligible_list') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('trainer.col_participant') }}</th>
                                <th scope="col">{{ __('attendance.rate.label') }}</th>
                                <th scope="col">{{ __('certificates.final_score') }}</th>
                                <th scope="col">{{ __('certificates.admin.reasons_column') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($notEligible as $person)
                                <tr>
                                    <th scope="row">
                                        <span class="cellpair">
                                            <x-ui.avatar size="sm" :name="$person->name" />
                                            {{ $person->name }}
                                        </span>
                                    </th>
                                    <td>
                                        <x-ui.pill :variant="$person->attendanceMet ? 'success' : 'danger'"
                                            :icon="$person->attendanceMet ? 'check' : 'warn'">
                                            <span class="u-num">{{ $person->attendancePercent }}%</span>
                                        </x-ui.pill>
                                    </td>
                                    <td>
                                        <x-ui.pill :variant="$person->scoreMet ? 'success' : 'danger'"
                                            :icon="$person->scoreMet ? 'check' : 'warn'">
                                            <span class="u-num">{{ $person->score }} / {{ $person->scoreMax }}</span>
                                        </x-ui.pill>
                                    </td>
                                    <td>
                                        {{-- Every reason, in full: this is the decision record. --}}
                                        <ul class="note__list" role="list">
                                            @foreach ($person->reasons as $reason)
                                                <li>{{ $reason }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                    <td class="u-nowrap">
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('admin.certificates.index', array_merge(request()->query(), ['override' => $person->id]))">{{ __('certificates.admin.override') }}</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="footnote">{{ __('certificates.both_required') }}</p>
            @endif
        </x-ui.card>

        {{-- Manual override — reason mandatory and audited ------------------------------ --}}
        @if ($overriding)
            <x-ui.card class="dc--span u-mt-4" icon="shield" :title="__('certificates.admin.override')">
                <div class="note note--warn">
                    <b>{{ $overriding->name }}</b>
                    {{ __('certificates.admin.override_reason_hint') }}
                </div>

                <ul class="note__list" role="list">
                    @foreach ($overriding->reasons as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>

                <form method="POST" action="{{ route('admin.certificates.override', $overriding->id) }}">
                    @csrf

                    <x-ui.textarea name="override_reason" rows="3" required minlength="10"
                        :label="__('certificates.admin.override_reason')"
                        :hint="__('certificates.admin.override_reason_hint')"
                        :value="old('override_reason')" />

                    <div class="row__acts">
                        <x-ui.button variant="danger" type="submit">{{ __('certificates.admin.issue_one') }}</x-ui.button>
                        <x-ui.button variant="ghost"
                            :href="route('admin.certificates.index', request()->except('override'))">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        {{-- Issued certificates ---------------------------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="badge" :title="__('admin.stats.certificates_issued')" flush>
            @if (is_null($issued))
                <div class="tscroll">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 5; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-3)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-1)" /></td>
                                    <td><x-ui.skeleton height="var(--s6)" width="var(--s18)" rounded="full" /></td>
                                    <td><x-ui.skeleton height="var(--s9)" width="var(--d-2)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($issued->isEmpty())
                <x-ui.empty-state icon="badge"
                    :title="__('certificates.admin.empty_title')"
                    :description="__('certificates.admin.empty_body')" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('admin.stats.certificates_issued') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('trainer.col_participant') }}</th>
                                <th scope="col">{{ __('certificates.serial_number') }}</th>
                                <th scope="col">{{ __('certificates.issued_on') }}</th>
                                <th scope="col">{{ __('admin.users.table.status') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($issued as $certificate)
                                <tr>
                                    <th scope="row">{{ $certificate->holderName }}</th>
                                    <td dir="ltr" class="u-ltr u-num">{{ $certificate->serialNumber }}</td>
                                    <td class="u-num u-nowrap">{{ \App\Support\Dates::longDate($certificate->issuedAt) }}</td>
                                    <td>
                                        <x-ui.pill :variant="$certificate->statusVariant" :icon="$certificate->statusIcon">{{ $certificate->statusLabel }}</x-ui.pill>
                                    </td>
                                    <td class="u-nowrap">
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('certificate.verify', $certificate->verifyCode)">{{ __('app.view_details') }}</x-ui.button>

                                        @if ($certificate->isRevoked)
                                            <form method="POST" action="{{ route('admin.certificates.reissue', $certificate->id) }}">
                                                @csrf
                                                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('certificates.admin.reissue') }}</x-ui.button>
                                            </form>
                                        @else
                                            <x-ui.button variant="danger" size="sm"
                                                :href="route('admin.certificates.index', array_merge(request()->query(), ['revoke' => $certificate->id]))">{{ __('certificates.admin.revoke') }}</x-ui.button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$issued" />
            @endif
        </x-ui.card>

        {{-- Revocation, reason mandatory --------------------------------------------------- --}}
        @if ($revoking)
            <x-ui.card class="dc--span u-mt-4" icon="warn" :title="__('certificates.admin.revoke')">
                <div class="note note--bad">
                    <b>{{ $revoking->holderName }}</b>
                    <span dir="ltr" class="u-ltr u-num">{{ $revoking->serialNumber }}</span>
                    {{ __('certificates.admin.revoked_done') }}
                </div>

                <form method="POST" action="{{ route('admin.certificates.revoke', $revoking->id) }}">
                    @csrf
                    @method('DELETE')

                    <x-ui.textarea name="revoke_reason" rows="3" required minlength="10"
                        :label="__('certificates.admin.revoke_reason')"
                        :hint="__('certificates.admin.override_reason_hint')"
                        :value="old('revoke_reason')" />

                    <div class="row__acts">
                        <x-ui.button variant="danger" type="submit">{{ __('certificates.admin.revoke') }}</x-ui.button>
                        <x-ui.button variant="ghost"
                            :href="route('admin.certificates.index', request()->except('revoke'))">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif
    @endif
@endsection
