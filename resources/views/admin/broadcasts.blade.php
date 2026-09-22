{{--
    Admin · write to a cohort's trainees, and send the reminders by hand.

    WHY THIS SCREEN EXISTS (D-87)
    There was no way to tell a cohort anything by e-mail: posting in the
    announcements channel sends a 120-character excerpt, and the session and
    assignment reminders were automatic only. The automatic ones stay exactly
    as they are — this adds the manual ones beside them, and a message in the
    administrator's own words.

    THREE CARDS
      1. A message: cohort, subject, body, and whether it also lands in the
         platform's notifications. Each cohort option says how many trainees
         it reaches and how many take the administration's messages by e-mail
         — a trainee who switched them off is not written to.
      2. Manual reminders: every upcoming session in one letter, or each
         trainee's own unsubmitted work in one letter.
      3. What was sent: when, to whom, what, to how many, by whom.

    Four states: error · loading (the history, shaped like its table) · empty
    (no cohort to write to; nothing sent yet) · normal.

    @see PRD §9.16, §9.16.1, §9.18 · BR-22, BR-23, BR-33 · D-87
--}}
@extends('layouts.app')

@section('title', __('admin.broadcasts.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.broadcasts.index')" />
    @elseif (count($cohortOptions) === 0)
        <x-ui.empty-state icon="mail"
            :title="__('admin.broadcasts.no_cohort_title')"
            :description="__('admin.broadcasts.no_cohort_body')"
            :action-label="__('admin.broadcasts.no_cohort_action')" :action-href="route('admin.cohorts.index')" />
    @else
        <p class="form__note">{{ __('admin.broadcasts.intro') }}</p>

        {{-- 1 · A message in the administrator's own words. --}}
        <x-ui.card icon="mail" :title="__('admin.broadcasts.message_title')" class="u-mt-4">
            @error('broadcast', 'broadcast')
                <div class="note note--bad" role="alert">
                    <svg aria-hidden="true"><use href="#i-warn"/></svg>
                    <div><p>{{ $message }}</p></div>
                </div>
            @enderror

            <form method="POST" action="{{ route('admin.broadcasts.store') }}" class="form">
                @csrf

                {{-- Two forms on one page, a cohort field in each: own ids, and
                     the old value only in the form that was sent. --}}
                <x-ui.select name="cohort_id" id="broadcast-cohort" required
                    :label="__('admin.broadcasts.fields.cohort')"
                    :options="$cohortOptions"
                    :value="$errors->reminder->any() ? null : old('cohort_id')"
                    :error="$errors->broadcast->first('cohort_id')"
                    :hint="__('admin.broadcasts.cohort_hint')" />

                <x-ui.input name="subject" required maxlength="150"
                    :label="__('admin.broadcasts.fields.subject')"
                    :value="old('subject')"
                    :error="$errors->broadcast->first('subject')"
                    :placeholder="__('admin.broadcasts.subject_placeholder')" />

                <x-ui.textarea name="body" required rows="8" :maxlength="$bodyMax"
                    :label="__('admin.broadcasts.fields.body')"
                    :value="old('body')"
                    :error="$errors->broadcast->first('body')"
                    :hint="__('admin.broadcasts.body_hint')"
                    :placeholder="__('admin.broadcasts.body_placeholder')" />

                <x-ui.checkbox name="in_app" value="1"
                    :checked="(bool) old('in_app', true)"
                    :label="__('admin.broadcasts.fields.in_app')"
                    :description="__('admin.broadcasts.in_app_hint')" />

                <div class="form__submit">
                    <x-ui.button variant="primary" type="submit" icon="mail">{{ __('admin.broadcasts.send') }}</x-ui.button>
                </div>
                <p class="form__note">{{ __('admin.broadcasts.send_note') }}</p>
            </form>
        </x-ui.card>

        {{-- 2 · The reminders, by hand. The automatic ones are untouched. --}}
        <x-ui.card icon="bell" :title="__('admin.broadcasts.reminders_title')" class="u-mt-4">
            <p class="form__note">{{ __('admin.broadcasts.reminders_body') }}</p>

            @error('reminder', 'reminder')
                <div class="note note--bad" role="alert">
                    <svg aria-hidden="true"><use href="#i-warn"/></svg>
                    <div><p>{{ $message }}</p></div>
                </div>
            @enderror

            <form method="POST" action="{{ route('admin.broadcasts.remind') }}" class="form">
                @csrf

                <x-ui.select name="cohort_id" id="reminder-cohort" required
                    :label="__('admin.broadcasts.fields.cohort')"
                    :options="$cohortOptions"
                    :value="$errors->reminder->any() ? old('cohort_id') : null"
                    :error="$errors->reminder->first('cohort_id')" />

                {{-- Two buttons, one form: the pressed button names the kind. --}}
                <div class="form__submit">
                    <x-ui.button variant="secondary" type="submit" name="kind" value="sessions" icon="cal">
                        {{ __('admin.broadcasts.remind_sessions') }}
                    </x-ui.button>
                    <x-ui.button variant="secondary" type="submit" name="kind" value="assignments" icon="file">
                        {{ __('admin.broadcasts.remind_assignments') }}
                    </x-ui.button>
                </div>
                <p class="form__note">{{ trans_choice('admin.broadcasts.cooldown_note', $cooldownMinutes, ['minutes' => $cooldownMinutes]) }}</p>
            </form>
        </x-ui.card>

        {{-- 3 · What was sent. --}}
        <x-ui.card class="dc--span u-mt-4" flush icon="clock" :title="__('admin.broadcasts.history_title')">
            @if (is_null($history))
                <div class="tscroll" aria-busy="true">
                    <x-ui.skeleton shape="text" :lines="5" :label="__('admin.states.loading')" />
                </div>
            @elseif ($history->isEmpty())
                <x-ui.empty-state icon="mail"
                    :title="__('admin.broadcasts.history_empty_title')"
                    :description="__('admin.broadcasts.history_empty_body')" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('admin.broadcasts.history_title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('admin.broadcasts.table.sent_at') }}</th>
                                <th scope="col">{{ __('admin.broadcasts.table.kind') }}</th>
                                <th scope="col">{{ __('admin.broadcasts.table.subject') }}</th>
                                <th scope="col">{{ __('admin.broadcasts.table.cohort') }}</th>
                                <th scope="col">{{ __('admin.broadcasts.table.recipients') }}</th>
                                <th scope="col">{{ __('admin.broadcasts.table.emails') }}</th>
                                <th scope="col">{{ __('admin.broadcasts.table.sender') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $row)
                                <tr>
                                    <th scope="row" class="u-num">{{ $row->sentAt }}</th>
                                    <td><x-ui.pill :variant="$row->kindVariant">{{ $row->kindLabel }}</x-ui.pill></td>
                                    <td>{{ $row->subject }}</td>
                                    <td>{{ $row->cohortName }}</td>
                                    <td class="u-num">{{ $row->recipients }}</td>
                                    <td class="u-num">{{ $row->emails }}</td>
                                    <td>{{ $row->senderName }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-ui.pagination :paginator="$history" />
            @endif
        </x-ui.card>
    @endif
@endsection
