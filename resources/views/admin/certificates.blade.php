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
                <x-ui.select clearable name="cohort" :label="__('admin.cohorts.title')"
                    :placeholder="__('certificates.admin.current_cohort')"
                    :options="$cohortOptions" :value="request('cohort')" />
                <x-ui.search-input name="q" :value="request('q')"
                    :placeholder="__('admin.users.filters.search_placeholder')" />
                <x-ui.button icon="filter" variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>
            <div class="toolbar__end">
                {{-- D-117 — no export from inside an account preview. --}}
                @unless ($impersonation ?? null)
                    <x-ui.button icon="download" variant="secondary" size="sm"
                        :href="route('admin.certificates.export', request()->query())">{{ __('app.export_excel') }}</x-ui.button>
                @endunless
            </div>
        </div>

        {{-- A refusal of the issue forms (not eligible, already issued, nobody ticked)
             comes back as validation errors. The reason fields print their own below
             the box; everything else is printed here, once (D-144). --}}
        @if ($formErrors !== [])
            <div class="note note--bad u-mt-4" role="alert" data-open-panel tabindex="-1">
                <b>{{ __('certificates.admin.form_error_title') }}</b>
                <ul class="note__list" role="list">
                    @foreach ($formErrors as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

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
                    :title="$isSearching ? __('certificates.admin.no_match_title') : __('certificates.admin.empty_title')"
                    :description="$isSearching ? __('certificates.admin.no_match_body') : __('certificates.admin.empty_body')" />
            @else
                {{-- Two ways to issue, and each is its own form (D-144):
                     • the boxes + «Issue to those eligible» issue to whoever is ticked;
                     • the button on a row issues to that ONE person. It cannot live inside
                       the bulk form — a submit button posts the form it is in, ticked boxes
                       and all — so it belongs to a small form of its own, below, by `form=`.
                     The boxes are the bulk form's own named inputs; the script only counts
                     them, so the form works with no script at all. --}}
                <form method="POST" action="{{ route('admin.certificates.issueBulk') }}" class="bulkform" id="bulk-issue-form"
                    x-data="atharBulkSelect({ name: 'user_id[]', spoken: @js(__('certificates.admin.selected_spoken'), JSON_UNESCAPED_UNICODE) })"
                    x-on:change="count()" x-on:pageshow.window="count()">
                    @csrf
                    <input type="hidden" name="cohort_id" value="{{ $cohortId }}">

                    <div class="selectbar">
                        <x-ui.checkbox :with-false="false" x-ref="all" x-on:change="toggleAll($event.target.checked)"
                            :label="__('certificates.admin.select_all')" />
                        <p class="selectbar__hint">{{ __('certificates.admin.select_hint') }}</p>
                        <p class="ui-sr" role="status" aria-live="polite" aria-atomic="true" x-text="spokenCount">{{ __('certificates.admin.selected_spoken', ['picked' => 0, 'total' => $eligible->count()]) }}</p>
                    </div>

                    <div class="tscroll">
                        <table class="atable atable--stack atable--pickable" role="table">
                            <caption class="sr">{{ __('certificates.admin.eligible_list') }}</caption>
                            <thead role="rowgroup">
                                <tr role="row">
                                    <th scope="col" role="columnheader"><span class="sr">{{ __('app.select') }}</span></th>
                                    <th scope="col" role="columnheader">{{ __('trainer.col_participant') }}</th>
                                    <th scope="col" role="columnheader">{{ __('attendance.rate.title') }}</th>
                                    <th scope="col" role="columnheader">{{ __('certificates.final_score') }}</th>
                                    <th scope="col" role="columnheader"><span class="sr">{{ __('app.actions.label') }}</span></th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup">
                                @foreach ($eligible as $candidate)
                                    <tr role="row" data-participant="{{ $candidate->id }}">
                                        <td role="cell" class="atable__pick" data-label="{{ __('app.select') }}">
                                            <x-ui.checkbox name="user_id[]" :value="$candidate->id"
                                                :checked="in_array($candidate->id, (array) old('user_id', []), true)"
                                                :label="__('certificates.admin.select_candidate', ['name' => $candidate->name])"
                                                label-hidden />
                                        </td>
                                        <th scope="row" role="rowheader" class="atable__lead">
                                            <span class="cellpair cellpair--inline">
                                                <x-ui.avatar size="sm" :name="$candidate->name" decorative />
                                                {{ $candidate->name }}
                                            </span>
                                        </th>
                                        <td role="cell" data-cell="half" data-label="{{ __('attendance.rate.title') }}">
                                            <x-ui.pill variant="success" icon="check">
                                                <span class="u-num">{{ $candidate->attendancePercent }}%</span>
                                            </x-ui.pill>
                                        </td>
                                        <td role="cell" data-cell="half" class="u-nowrap" data-label="{{ __('certificates.final_score') }}"><span class="u-num">{{ $candidate->score }} / {{ $candidate->scoreMax }}</span></td>
                                        <td role="cell" data-cell="full" class="u-nowrap">
                                            <x-ui.button icon="badge" variant="primary" size="sm" type="submit"
                                                :form="'issue-'.$candidate->id" :data-issue-one="$candidate->id"
                                                :context="$candidate->name">{{ __('certificates.admin.issue_one') }}</x-ui.button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Appears once someone is ticked and then stays in view (the roster's bar). --}}
                    <div class="bulkbar" x-bind:class="{ 'is-idle': picked === 0 }">
                        <p class="bulkbar__count">
                            <span>{{ __('certificates.admin.selected_label') }}</span>
                            <b class="u-num"><span x-text="picked">0</span> / <span x-text="total">{{ $eligible->count() }}</span></b>
                        </p>
                        {{-- One press issues to everyone ticked, so with script it first says how many
                             (D-147). Without script it is the plain submit and the server decides. --}}
                        <x-ui.button icon="badge" variant="primary" size="sm" type="submit" class="bulkbar__apply"
                            data-action="issue-selected" x-bind:disabled="picked === 0"
                            x-on:click.prevent="$dispatch('ui-dialog-open', 'confirm-issue-selected')">{{ __('certificates.admin.issue_selected') }}</x-ui.button>
                    </div>

                    <x-ui.confirm name="confirm-issue-selected" target-form="bulk-issue-form" variant="primary" icon="badge"
                        :title="__('certificates.admin.confirm_issue_title')" :description="__('certificates.admin.confirm_issue_body')"
                        :confirm-label="__('certificates.admin.confirm_issue_action')" confirm-icon="badge"
                        described-by="issue-selected-summary">
                        <p id="issue-selected-summary">{{ __('certificates.admin.selected_label') }} <b class="u-num" x-text="picked">0</b></p>
                    </x-ui.confirm>
                </form>

                @foreach ($eligible as $candidate)
                    <form method="POST" action="{{ route('admin.certificates.issue') }}" id="issue-{{ $candidate->id }}" hidden>
                        @csrf
                        <input type="hidden" name="user_id" value="{{ $candidate->id }}">
                        <input type="hidden" name="cohort_id" value="{{ $cohortId }}">
                    </form>
                @endforeach

                <p class="hint">
                    <x-ui.icon name="badge" />
                    {{ __('certificates.admin.serial_format_hint') }}
                </p>
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
                {{-- "Nobody falls short" is a claim about the whole cohort: under a
                     search it would be false, so a search says only "no match". --}}
                @if ($isSearching)
                    <x-ui.empty-state icon="badge"
                        :title="__('certificates.admin.no_match_title')"
                        :description="__('certificates.admin.no_match_body')" />
                @else
                    <x-ui.empty-state variant="success" icon="check"
                        :title="__('certificates.admin.none_ineligible_title')"
                        :description="__('certificates.both_required')" />
                @endif
            @else
                <div class="tscroll">
                    <table class="atable atable--stack" role="table">
                        <caption class="sr">{{ __('certificates.admin.not_eligible_list') }}</caption>
                        <thead role="rowgroup">
                            <tr role="row">
                                <th scope="col" role="columnheader">{{ __('trainer.col_participant') }}</th>
                                <th scope="col" role="columnheader">{{ __('attendance.rate.title') }}</th>
                                <th scope="col" role="columnheader">{{ __('certificates.final_score') }}</th>
                                <th scope="col" role="columnheader">{{ __('certificates.admin.reasons_column') }}</th>
                                <th scope="col" role="columnheader"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody role="rowgroup">
                            @foreach ($notEligible as $person)
                                <tr role="row">
                                    <th scope="row" role="rowheader" class="atable__lead">
                                        <span class="cellpair cellpair--inline">
                                            <x-ui.avatar size="sm" :name="$person->name" decorative />
                                            {{ $person->name }}
                                        </span>
                                    </th>
                                    <td role="cell" data-label="{{ __('attendance.rate.title') }}">
                                        <x-ui.pill :variant="$person->attendanceMet ? 'success' : 'danger'"
                                            :icon="$person->attendanceMet ? 'check' : 'warn'">
                                            <span class="u-num">{{ $person->attendancePercent }}%</span>
                                        </x-ui.pill>
                                    </td>
                                    <td role="cell" data-label="{{ __('certificates.final_score') }}">
                                        <x-ui.pill :variant="$person->scoreMet ? 'success' : 'danger'"
                                            :icon="$person->scoreMet ? 'check' : 'warn'">
                                            <span class="u-num">{{ $person->score }} / {{ $person->scoreMax }}</span>
                                        </x-ui.pill>
                                    </td>
                                    <td role="cell" data-label="{{ __('certificates.admin.reasons_column') }}">
                                        {{-- Every reason, in full: this is the decision record. --}}
                                        <ul class="note__list" role="list">
                                            @foreach ($person->reasons as $reason)
                                                <li>{{ $reason }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                    <td role="cell" class="u-nowrap">
                                        <x-ui.button icon="key" variant="secondary" size="sm" :context="$person->name"
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
            <x-ui.card data-open-panel tabindex="-1" class="dc--span u-mt-4" icon="key" :title="__('certificates.admin.override')">
                <div class="note note--warn">
                    <b>{{ $overriding->name }}</b>
                    {{ __('certificates.admin.override_note') }}
                </div>

                <ul class="note__list" role="list">
                    @foreach ($overriding->reasons as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>

                <form method="POST" action="{{ route('admin.certificates.override', $overriding->id) }}">
                    @csrf
                    <input type="hidden" name="cohort_id" value="{{ $cohortId }}">

                    <x-ui.textarea name="override_reason" rows="3" required minlength="10"
                        :label="__('certificates.admin.override_reason')"
                        :hint="__('certificates.admin.override_reason_hint')"
                        :value="old('override_reason')" />

                    <div class="row__acts">
                        <x-ui.button icon="badge" variant="danger" type="submit">{{ __('certificates.admin.issue_one') }}</x-ui.button>
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
                    <table class="atable atable--skel">
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
                    :title="$isSearching ? __('certificates.admin.no_match_title') : __('certificates.admin.issued_empty_title')"
                    :description="$isSearching ? __('certificates.admin.no_match_body') : __('certificates.admin.issued_empty_body')" />
            @else
                <div class="tscroll">
                    <table class="atable atable--stack" role="table">
                        <caption class="sr">{{ __('admin.stats.certificates_issued') }}</caption>
                        <thead role="rowgroup">
                            <tr role="row">
                                <th scope="col" role="columnheader">{{ __('trainer.col_participant') }}</th>
                                <th scope="col" role="columnheader">{{ __('certificates.serial_number') }}</th>
                                <th scope="col" role="columnheader">{{ __('certificates.issued_on') }}</th>
                                <th scope="col" role="columnheader">{{ __('admin.users.table.status') }}</th>
                                <th scope="col" role="columnheader"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody role="rowgroup">
                            @foreach ($issued as $certificate)
                                <tr role="row">
                                    <th scope="row" role="rowheader" class="atable__lead">{{ $certificate->holderName }}</th>
                                    <td role="cell" data-label="{{ __('certificates.serial_number') }}"><span dir="ltr" class="u-ltr u-num">{{ $certificate->serialNumber }}</span></td>
                                    <td role="cell" class="u-when u-nowrap" data-label="{{ __('certificates.issued_on') }}">{{ \App\Support\Dates::longDate($certificate->issuedAt) }}</td>
                                    <td role="cell" data-label="{{ __('admin.users.table.status') }}">
                                        <x-ui.pill :variant="$certificate->statusVariant" :icon="$certificate->statusIcon">{{ $certificate->statusLabel }}</x-ui.pill>
                                    </td>
                                    <td role="cell" class="u-nowrap">
                                        <div class="row__acts">
                                            <x-ui.button icon="eye" :icon-only="true" variant="secondary" size="sm" :context="$certificate->holderName"
                                                :href="route('certificate.verify', $certificate->verifyCode)">{{ __('app.view_details') }}</x-ui.button>

                                            @if ($certificate->isRevoked)
                                                <x-ui.confirm name="reissue-{{ $certificate->id }}"
                                                    :action="route('admin.certificates.reissue', $certificate->id)" variant="primary" icon="refresh"
                                                    :title="__('certificates.admin.reissue_title', ['name' => $certificate->holderName])"
                                                    :description="__('certificates.admin.reissue_body')"
                                                    :confirm-label="__('certificates.admin.reissue')" confirm-icon="refresh"
                                                    :trigger-label="__('certificates.admin.reissue')" trigger-icon="refresh"
                                                    trigger-variant="secondary" :trigger-context="$certificate->holderName" />
                                            @else
                                                <x-ui.button icon="ban" variant="danger-ghost" size="sm" :context="$certificate->holderName"
                                                    :href="route('admin.certificates.index', array_merge(request()->query(), ['revoke' => $certificate->id]))">{{ __('certificates.admin.revoke') }}</x-ui.button>
                                            @endif
                                        </div>
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
            <x-ui.card data-open-panel tabindex="-1" class="dc--span u-mt-4" icon="warn" :title="__('certificates.admin.revoke')">
                <div class="note note--bad">
                    <b>{{ $revoking->holderName }}</b>
                    <span dir="ltr" class="u-ltr u-num">{{ $revoking->serialNumber }}</span>
                    {{ __('certificates.admin.revoke_warning') }}
                </div>

                <form method="POST" action="{{ route('admin.certificates.revoke', $revoking->id) }}">
                    @csrf
                    @method('DELETE')

                    <x-ui.textarea name="revoke_reason" rows="3" required minlength="10"
                        :label="__('certificates.admin.revoke_reason')"
                        :hint="__('certificates.admin.revoke_reason_hint')"
                        :value="old('revoke_reason')" />

                    <div class="row__acts">
                        <x-ui.button icon="ban" variant="danger" type="submit">{{ __('certificates.admin.revoke') }}</x-ui.button>
                        <x-ui.button variant="ghost"
                            :href="route('admin.certificates.index', request()->except('revoke'))">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif
    @endif
@endsection
