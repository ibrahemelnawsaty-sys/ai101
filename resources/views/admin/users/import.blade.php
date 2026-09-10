{{--
    Admin · bring a whole cohort in from one sheet.

    Two screens in one file, because they are two steps of one act and splitting
    them would put the rules on one page and the results on another.

      1. UPLOAD  — choose the cohort, download the sheet, send it back filled.
      2. PREVIEW — every row, with its errors, before anything is written.

    THE PREVIEW IS THE POINT. Sixty rows typed by hand will contain a repeated
    address and a phone number with a space in it. An import that discovers that
    halfway through leaves half a cohort created and no way to tell which half.
    Nothing is written until the second button.

    A rejected row is shown WITH ITS REASON, on its own numbered line, using the
    number the administrator sees in Excel. "Row 34: this address already has an
    account" is a thing a person can fix. "The file is invalid" is not.

    Four states: error · loading (none — both steps are plain form posts) ·
    empty (no cohort exists) · normal.

    @see PRD §4.2, §4.5.1 · CONSTITUTION Art. 5, Art. 7, Art. 17 · D-63

    Variables from App\Http\Controllers\Admin\UserImportController:
      $cohortOptions  array
      $rows           list<App\Services\Import\ImportedRow>|null
      $validCount     int
      $rejectedCount  int
      $cohortId       string|null
      $maxRows        int
--}}
@extends('layouts.app')

@section('title', __('admin.users.import.title'))
@section('subtitle', __('admin.users.import.subtitle'))

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.users.import')" />
    @elseif (count($cohortOptions) === 0)
        <x-ui.empty-state icon="users"
            :title="__('admin.users.no_cohort_title')"
            :description="__('admin.users.no_cohort_body')"
            :action-label="__('admin.users.no_cohort_action')" :action-href="route('admin.cohorts.index')" />
    @else
        @if ($errors->any())
            <div class="note note--bad" role="alert" aria-live="polite">
                <b>{{ __('auth.shared.error_summary_title') }}</b>
                <ul>
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (is_null($rows))
            {{-- ── Step 1 · the sheet ─────────────────────────────────────── --}}
            <x-ui.card icon="down" :title="__('admin.users.import.step_template')">
                <p class="form__note">{{ __('admin.users.import.template_hint') }}</p>

                <div class="tagrow u-mt-4">
                    <x-ui.button variant="primary"
                        :href="route('admin.users.import.template')">{{ __('admin.users.import.template_xlsx') }}</x-ui.button>
                    <x-ui.button variant="secondary" size="sm"
                        :href="route('admin.users.import.templateCsv')">{{ __('admin.users.import.template_csv') }}</x-ui.button>
                </div>

                {{-- Not a footnote. Every row of a CSV fails on this one, and the
                     file looks correct on screen while it does. --}}
                <div class="note note--warn u-mt-4">
                    <svg aria-hidden="true"><use href="#i-warn"/></svg>
                    <div>
                        <b>{{ __('admin.users.import.csv_warning_title') }}</b>
                        <p>{{ __('admin.users.import.csv_warning_body') }}</p>
                    </div>
                </div>
            </x-ui.card>

            {{-- ── Step 2 · send it back ──────────────────────────────────── --}}
            <form method="POST" action="{{ route('admin.users.import.preview') }}"
                  enctype="multipart/form-data" class="u-mt-4">
                @csrf

                <x-ui.card icon="up" :title="__('admin.users.import.step_upload')">
                    <div class="grid-fields">
                        <x-ui.select name="cohort_id" required
                            :label="__('admin.users.cohort')" :options="$cohortOptions"
                            :value="old('cohort_id', $cohortId)"
                            :hint="__('admin.users.import.cohort_hint')" />

                        <div class="ui-field">
                            <label class="ui-field__label" for="import-sheet">
                                {{ __('admin.users.import.file_label') }}
                                <i class="ui-field__required" aria-hidden="true">*</i>
                            </label>
                            {{-- A native input, not `x-ui.input`: that component
                                 is a text-field shell with no file mode, and it
                                 would have rendered a text box. It is also the
                                 right answer on its own terms — this form has to
                                 work with JavaScript switched off (D-54). --}}
                            <input type="file" name="sheet" id="import-sheet" required
                                   accept=".xlsx,.csv"
                                   aria-describedby="import-sheet-hint"
                                   class="ui-input">
                            <p class="ui-field__hint" id="import-sheet-hint">
                                {{ __('admin.users.import.file_hint', ['max' => $maxRows]) }}
                            </p>
                        </div>
                    </div>

                    <div class="form__submit">
                        <x-ui.button variant="primary" type="submit">{{ __('admin.users.import.preview_submit') }}</x-ui.button>
                    </div>

                    <p class="form__note">{{ __('admin.users.import.preview_note') }}</p>
                </x-ui.card>
            </form>
        @else
            <x-ui.card icon="file" :title="__('admin.users.import.review_title')">
                <p class="form__note">
                    {{ __('admin.users.import.summary', ['valid' => $validCount, 'invalid' => $rejectedCount]) }}
                </p>

                <div class="tscroll u-mt-4">
                    <table class="atable">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('admin.users.import.row') }}</th>
                                <th scope="col">{{ __('admin.users.export.name') }}</th>
                                <th scope="col">{{ __('auth.shared.email') }}</th>
                                <th scope="col">{{ __('admin.users.table.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td class="u-num">{{ $row->number }}</td>
                                    <td>{{ $row->displayName() }}</td>
                                    <td dir="ltr" class="row__ltr">{{ $row->email() }}</td>
                                    <td>
                                        @if ($row->isValid())
                                            <x-ui.pill variant="success" icon="check">{{ __('admin.users.import.row_ok') }}</x-ui.pill>
                                        @else
                                            <x-ui.pill variant="error" icon="warn">{{ __('admin.users.import.row_bad') }}</x-ui.pill>
                                            {{-- The reason, on the row it belongs to. --}}
                                            <ul class="row__why">
                                                @foreach ($row->errors as $message)
                                                    <li>{{ $message }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <form method="POST" action="{{ route('admin.users.import.store') }}" class="u-mt-4">
                @csrf

                {{-- The rejected rows are simply not imported. An all-or-nothing
                     refusal would mean one mistyped phone number blocks the
                     other fifty-nine people, and the administrator would have
                     to find it before anybody could start. --}}
                <div class="form__submit">
                    <x-ui.button variant="primary" type="submit"
                        :state="$validCount === 0 ? 'disabled' : 'default'">
                        {{ __('admin.users.import.confirm', ['count' => $validCount]) }}
                    </x-ui.button>
                    <x-ui.button variant="secondary" :href="route('admin.users.import')">{{ __('app.cancel') }}</x-ui.button>
                </div>

                <p class="form__note">{{ __('admin.users.import.confirm_note') }}</p>
            </form>
        @endif
    @endif
@endsection
