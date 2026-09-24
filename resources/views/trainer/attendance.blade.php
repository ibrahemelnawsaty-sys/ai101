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
            {{-- D-106: the self-check-in QR, only while its window is actually
                 open — the same [S, S+60m] the server itself enforces. The
                 underlying signed url only ever changes once every ten
                 minutes (CheckinCode), so polling this every minute is enough
                 to catch that rotation without competing with the roster's
                 own much faster poll below. --}}
            <x-ui.card class="dc--span" icon="video" :title="__('attendance.checkin_code.title')">
                {{-- D-116 — the state lives on a plain element inside the card, as
                     the roster's does below it. It used to sit on the card tag
                     itself, and Blade compiles no directive inside a component
                     tag's attributes: the browser received the literal text
                     `@js(...)`, the expression threw, and this card never once
                     showed the code to anyone. --}}
                <div x-data="atharCheckinCode({
                        pollUrl: '{{ route('trainer.attendance.checkinCode', $roster->sessionId) }}',
                        pollSeconds: 60,
                        initialOpen: @js($checkInCode !== null),
                        initialUrl: @js($checkInCode?->get('url') ?? ''),
                        initialSvg: @js($checkInCode?->get('svg') ?? ''),
                    })">
                    <template x-if="open">
                        <div>
                            <p class="u-muted">{{ __('attendance.checkin_code.body') }}</p>
                            <div class="checkincode">
                                <div class="checkincode__qr" x-html="svg"></div>
                                <p>
                                    {{ __('attendance.checkin_code.link_label') }}
                                    <a :href="url" x-text="url" dir="ltr" class="u-num"></a>
                                </p>
                            </div>
                        </div>
                    </template>
                    <template x-if="! open">
                        <x-ui.empty-state icon="clock" size="sm"
                            :title="__('attendance.checkin_code.closed_title')"
                            :description="__('attendance.checkin_code.closed_body')" />
                    </template>
                </div>
            </x-ui.card>

            <x-ui.card class="dc--span u-mt-4" flush>
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

        {{-- Pending excuse requests (D-106) -------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="warn" :title="__('attendance.exceptions_queue.title')" flush>
            @if ($pendingExceptions->isEmpty())
                <x-ui.empty-state icon="check" size="sm"
                    :title="__('attendance.exceptions_queue.empty_title')"
                    :description="__('attendance.exceptions_queue.empty_body')" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('attendance.exceptions_queue.title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('attendance.exceptions_queue.col_participant') }}</th>
                                <th scope="col">{{ __('attendance.exceptions_queue.col_session') }}</th>
                                <th scope="col">{{ __('attendance.exceptions_queue.col_type') }}</th>
                                <th scope="col">{{ __('attendance.exceptions_queue.col_reason') }}</th>
                                <th scope="col">{{ __('attendance.exceptions_queue.col_requested_at') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pendingExceptions as $item)
                                <tr>
                                    <th scope="row">{{ $item->participantName }}</th>
                                    <td>{{ $item->sessionTitle }} <span class="u-num u-muted">{{ $item->sessionDate }}</span></td>
                                    <td><x-ui.pill variant="neutral">{{ $item->typeLabel }}</x-ui.pill></td>
                                    <td>{{ $item->reason }}</td>
                                    <td class="u-num u-nowrap">{{ $item->requestedAt }}</td>
                                    <td>
                                        <div class="row__acts" x-data="{ rejecting: false }">
                                            <form method="POST" action="{{ route('trainer.attendance-exceptions.approve', $item->id) }}">
                                                @csrf
                                                <x-ui.button variant="primary" size="sm" type="submit">
                                                    {{ __('attendance.exceptions_queue.approve_action') }}
                                                </x-ui.button>
                                            </form>
                                            <x-ui.button variant="danger" size="sm" type="button" x-on:click="rejecting = ! rejecting">
                                                {{ __('attendance.exceptions_queue.reject_action') }}
                                            </x-ui.button>
                                            <form method="POST" x-show="rejecting" x-cloak
                                                action="{{ route('trainer.attendance-exceptions.reject', $item->id) }}" class="u-mt-2">
                                                @csrf
                                                <x-ui.textarea name="decision_reason" rows="2" required minlength="10"
                                                    :label="__('attendance.exceptions_queue.reject_reason_label')"
                                                    :placeholder="__('attendance.exceptions_queue.reject_reason_placeholder')"
                                                    :hint="__('attendance.exceptions_queue.reject_reason_hint', ['min' => 10])" />
                                                <x-ui.button variant="danger" size="sm" type="submit" class="u-mt-2">
                                                    {{ __('attendance.exceptions_queue.reject_action') }}
                                                </x-ui.button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        {{-- Recorded sessions (D-107) — the one place a recording link is set;
             trainer, admin and coordinator share this screen and this card,
             since no session editor field exists for it anywhere else. --}}
        <x-ui.card class="dc--span u-mt-4" icon="folder" :title="__('trainer.sessions.recordings_title')" flush>
            @if ($recordingSessions->isEmpty())
                <x-ui.empty-state icon="video" size="sm"
                    :title="__('trainer.sessions.recordings_empty_title')"
                    :description="__('trainer.sessions.recordings_empty_body')" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('trainer.sessions.recordings_title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('attendance.col_session') }}</th>
                                <th scope="col">{{ __('trainer.sessions.col_recording') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recordingSessions as $row)
                                <tr>
                                    <th scope="row">
                                        {{ $row->topic }}
                                        <span class="u-num u-muted">{{ $row->date }}</span>
                                    </th>
                                    <td>
                                        @if ($row->hasRecording)
                                            <x-ui.pill variant="success">{{ __('trainer.sessions.link_set') }}</x-ui.pill>
                                        @else
                                            <x-ui.pill variant="neutral">{{ __('trainer.sessions.link_missing') }}</x-ui.pill>
                                        @endif
                                    </td>
                                    <td>
                                        <x-ui.button variant="secondary" size="sm" :href="$row->editHref">
                                            {{ $row->hasRecording ? __('app.edit') : __('trainer.sessions.add_recording_action') }}
                                        </x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        {{-- Recording upload panel, open on ?recording={id} (D-107) ----------- --}}
        @if ($recordingEditing)
            <x-ui.card class="dc--span u-mt-4" icon="video"
                :title="__('trainer.sessions.recording_edit_title', ['topic' => $recordingEditing->topic])">

                <form method="POST" action="{{ route('trainer.sessions.recording', $recordingEditing->id) }}">
                    @csrf
                    @method('PATCH')

                    <x-ui.input name="recording_url" type="url" dir="ltr" maxlength="3000"
                        :label="__('trainer.sessions.recording_url')"
                        :hint="__('trainer.sessions.recording_url_hint')"
                        :value="old('recording_url', $recordingEditing->recordingUrl)" />

                    <div class="row__acts u-mt-3">
                        <x-ui.button variant="primary" size="sm" type="submit">{{ __('app.save_changes') }}</x-ui.button>
                        <x-ui.button variant="ghost" size="sm"
                            :href="route('trainer.attendance', request()->except('recording'))">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
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
