{{--
    Trainer attendance — the live session roster, manual edits, bulk marking and the
    cohort attendance matrix.

    Every manual edit REQUIRES a reason of at least 10 characters. The rule is enforced
    in the FormRequest and written to audit_logs before the change is applied (BR-27);
    the minlength below only mirrors it.

    @see PRD §9.9.7 · BR-07, BR-08, BR-09, BR-23, BR-27
--}}
@extends('layouts.app')

@section('title', __('trainer.attendance.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('trainer.attendance.error_title')"
            :description="__('trainer.attendance.error_body')"
            :action-label="__('app.retry')" :action-href="route('trainer.attendance')" />
    @else

        <form method="GET" action="{{ route('trainer.attendance') }}" class="toolbar">
            <x-ui.select name="session" :label="__('trainer.attendance.pick_session')" :options="$sessionOptions" :value="request('session')" />
            <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.show') }}</x-ui.button>
            <div class="toolbar__end">
                <x-ui.button variant="secondary" size="sm" icon="down"
                    :href="route('trainer.attendance.export', request()->query())">{{ __('app.export_excel') }}</x-ui.button>
            </div>
        </form>

        {{-- Live roster ------------------------------------------------------- --}}
        @if (is_null($roster))
            <x-ui.card>
                <x-ui.skeleton height="var(--s5)" width="46%" />
                <x-ui.skeleton height="var(--s3)" width="30%" class="u-mt-1" />
                <div class="tscroll u-mt-4">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 8; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s18)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s18)" /></td>
                                    <td><x-ui.skeleton height="var(--s6)" width="var(--s19)" rounded="full" /></td>
                                    <td><x-ui.skeleton height="var(--s9)" width="var(--s17)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @elseif ($roster->hasNoSession)
            <x-ui.empty-state icon="cal"
                :title="__('trainer.attendance.no_session_title')"
                :description="__('trainer.attendance.no_session_body')"
                :action-label="__('trainer.sessions.title')" :action-href="route('trainer.sessions')" />
        @else
            <x-ui.card class="dc--span" flush>
                {{-- The roster refreshes itself while the session is live (PRD §9.9.7):
                     the server re-renders the four cells that can change, and only
                     those are replaced — never the checkboxes or the reason being
                     typed, because this table is also the bulk-marking form. --}}
                <div x-data="atharRoster({
                        pollUrl: '{{ route('trainer.attendance.poll', $roster->sessionId) }}',
                        pollSeconds: @js($rosterPollSeconds),
                        live: @js($roster->isLive)
                    })">

                <div class="tablebar">
                    <div>
                        <b>{{ $roster->sessionTitle }}</b>
                        <span class="u-num">
                            {{ \App\Support\Dates::longDate($roster->startsAt) }} ·
                            {{ \App\Support\Dates::timeRange12($roster->startsAt, $roster->endsAt) }}
                        </span>
                    </div>
                    <div class="toolbar__end">
                        @if ($roster->isLive)
                            <x-ui.pill variant="live" icon="clock">{{ __('trainer.attendance.live_now') }}</x-ui.pill>
                        @endif
                        <b class="roster__count">
                            <span class="u-num" data-cell="present">{{ $roster->presentCount }}</span>
                            <small class="u-num"> / {{ $roster->totalCount }}</small>
                        </b>
                        <span class="u-muted">{{ __('trainer.attendance.checked_in_of_total') }}</span>
                    </div>
                </div>

                @if ($roster->entries->isEmpty())
                    <x-ui.empty-state icon="users"
                        :title="__('trainer.attendance.roster_empty_title')"
                        :description="__('trainer.attendance.roster_empty_body')"
                        :action-label="__('trainer.participants.title')" :action-href="route('trainer.participants')" />
                @else
                    {{-- Bulk marking, for emergencies only, reason required. --}}
                    <form method="POST" action="{{ route('trainer.attendance.bulk', $roster->sessionId) }}">
                        @csrf

                        <div class="tscroll">
                            <table class="atable">
                                <caption class="sr">{{ __('trainer.attendance.roster_caption', ['session' => $roster->sessionTitle]) }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col"><span class="sr">{{ __('app.select') }}</span></th>
                                        <th scope="col">{{ __('trainer.col_participant') }}</th>
                                        <th scope="col">{{ __('attendance.col_check_in') }}</th>
                                        <th scope="col">{{ __('attendance.col_check_out') }}</th>
                                        <th scope="col">{{ __('attendance.col_status') }}</th>
                                        <th scope="col">{{ __('trainer.attendance.col_edit') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($roster->entries as $entry)
                                        <tr data-participant="{{ $entry->participantId }}">
                                            <td>
                                                <x-ui.checkbox name="user_id[]" :value="$entry->participantId"
                                                    :label="__('trainer.attendance.select_participant', ['name' => $entry->participantName])"
                                                    label-hidden />
                                            </td>
                                            <th scope="row">
                                                <span class="cellpair">
                                                    <x-ui.avatar size="sm" :name="$entry->participantName" />
                                                    {{ $entry->participantName }}
                                                </span>
                                            </th>
                                            <td class="u-num" data-cell="in">{{ $entry->checkedInLabel }}</td>
                                            <td class="u-num" data-cell="out">{{ $entry->checkedOutLabel }}</td>
                                            <td data-cell="status">@include('trainer.partials.roster-status', ['entry' => $entry])</td>
                                            <td data-cell="edit">@include('trainer.partials.roster-edit', ['entry' => $entry])</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="tablebar">
                            <x-ui.select name="attendance_status" :label="__('trainer.attendance.bulk_status')" :options="$statusOptions" required />
                            <x-ui.input name="edit_reason" minlength="10" required
                                :label="__('trainer.attendance.reason')"
                                :hint="__('trainer.attendance.reason_hint', ['min' => 10])" />
                            <x-ui.button variant="secondary" size="sm" type="submit">{{ __('trainer.attendance.apply_bulk') }}</x-ui.button>
                        </div>
                    </form>
                @endif
                </div>
            </x-ui.card>
        @endif

        {{-- Single manual edit ------------------------------------------------- --}}
        @if ($editing)
            <x-ui.card class="dc--span u-mt-4" icon="shield"
                :title="__('trainer.attendance.edit_title', ['name' => $editing->participantName])">

                <div class="note note--warn">
                    <b>{{ __('trainer.attendance.edit_audited_title') }}</b>
                    {{ __('trainer.attendance.edit_audited_body') }}
                </div>

                <form method="POST" action="{{ route('trainer.attendance.update', $editing->id) }}"
                    x-data="{ reason: @js(old('edit_reason', '')) }">
                    @csrf
                    @method('PATCH')

                    <div class="f2">
                        <x-ui.select name="attendance_status" required
                            :label="__('attendance.col_status')"
                            :options="$statusOptions"
                            :value="old('attendance_status', $editing->status)" />
                    </div>

                    <x-ui.textarea name="edit_reason" rows="3" required minlength="10"
                        :label="__('trainer.attendance.reason')"
                        :placeholder="__('trainer.attendance.reason_placeholder')"
                        x-model="reason" />

                    <p class="hint" x-bind:class="reason.trim().length < 10 ? 'hint--bad' : ''"
                        role="status" aria-live="polite">
                        <x-ui.icon name="warn" />
                        <span x-show="reason.trim().length < 10">{{ __('trainer.attendance.reason_hint', ['min' => 10]) }}</span>
                        <span x-show="reason.trim().length >= 10" x-cloak>{{ __('trainer.attendance.reason_ok') }}</span>
                    </p>

                    <div class="row__acts">
                        <x-ui.button variant="primary" size="sm" type="submit">{{ __('app.save_changes') }}</x-ui.button>
                        <x-ui.button variant="ghost" size="sm"
                            :href="route('trainer.attendance', request()->except('edit'))">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        {{-- At-risk participants + cohort matrix -------------------------------- --}}
        <div class="dgrid u-mt-4">
            <x-ui.card icon="warn" :title="__('trainer.attendance.at_risk_title')">
                @if (is_null($atRisk))
                    @for ($i = 0; $i < 3; $i++)
                        <div class="row row--sk">
                            <div class="row__m"><x-ui.skeleton height="var(--s4)" width="58%" /></div>
                            <x-ui.skeleton height="var(--s3)" width="var(--touch-min)" />
                        </div>
                    @endfor
                @elseif ($atRisk->isEmpty())
                    <x-ui.empty-state icon="check" size="sm"
                        :title="__('trainer.attendance.at_risk_empty_title')"
                        :description="__('trainer.attendance.at_risk_empty_body')" />
                @else
                    @foreach ($atRisk as $person)
                        <div class="row">
                            <div class="row__m">
                                <b>{{ $person->participantName }}</b>
                                <span>{{ __('certificates.min_attendance', ['rate' => $person->minimumRatePercent]) }}</span>
                            </div>
                            <div class="row__e">
                                <x-ui.pill :variant="$person->rateVariant" icon="warn">
                                    <span class="u-num">{{ $person->ratePercent }}%</span>
                                </x-ui.pill>
                            </div>
                        </div>
                    @endforeach
                @endif
            </x-ui.card>

            <x-ui.card class="dc--2" icon="chart" :title="__('trainer.attendance.matrix_title')" flush>
                @if (is_null($matrix))
                    <div class="tscroll">
                        <table class="atable">
                            <tbody>
                                @for ($i = 0; $i < 6; $i++)
                                    <tr>
                                        <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                        @for ($j = 0; $j < 6; $j++)
                                            <td><x-ui.skeleton height="var(--s5)" width="var(--s5)" rounded="full" /></td>
                                        @endfor
                                    </tr>
                                @endfor
                            </tbody>
                        </table>
                    </div>
                @elseif ($matrix->rows->isEmpty())
                    <x-ui.empty-state icon="chart"
                        :title="__('trainer.attendance.matrix_empty_title')"
                        :description="__('trainer.attendance.matrix_empty_body')" />
                @else
                    <div class="tscroll">
                        <table class="atable mtable">
                            <caption class="sr">{{ __('trainer.attendance.matrix_title') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('trainer.col_participant') }}</th>
                                    @foreach ($matrix->sessions as $session)
                                        <th scope="col" class="u-num">
                                            <abbr title="{{ $session->topic }}">{{ \App\Support\Dates::shortDate($session->date) }}</abbr>
                                        </th>
                                    @endforeach
                                    <th scope="col">{{ __('attendance.rate.label') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($matrix->rows as $row)
                                    <tr>
                                        <th scope="row">{{ $row->participantName }}</th>
                                        @foreach ($row->cells as $cell)
                                            {{-- Colour alone never carries meaning: each cell has a letter and a title. --}}
                                            <td class="mcell mcell--{{ $cell->variant }}">
                                                <abbr title="{{ $cell->statusLabel }}">{{ $cell->shortCode }}</abbr>
                                            </td>
                                        @endforeach
                                        <td class="u-num">{{ $row->ratePercent }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <x-ui.pagination :paginator="$matrix->rows" />
                @endif
            </x-ui.card>
        </div>
    @endif
@endsection
