{{--
    A printable copy of the cohort timetable. The browser makes the PDF, so
    there is no PDF library and no second rendering path to keep in step.

    It reads the same SessionPresenter the schedule screen reads, so a status
    label or a time can never differ between what a participant sees and what
    they hand to someone (CONSTITUTION art. 6).

    Print rules that are easy to get wrong and are handled here:
      - the page is RTL and stays RTL on paper
      - every time is Riyadh and the sheet says so, because a printout leaves
        the context that made the timezone obvious (PRD 9.8.2)
      - a cancelled session stays on the sheet, struck through and with its
        reason, rather than vanishing: someone comparing an old printout with a
        new one has to be able to see what changed
      - the header row repeats on every page, so page two is still readable

    The bare layout loads no JavaScript on purpose, so this sheet does not
    auto-open the print dialog. The reader presses print, which also lets them
    read it on screen without a dialog in the way.

    @see PRD 9.8.2 - CONSTITUTION art. 6, art. 15, art. 16, art. 17
--}}
@extends('layouts.bare')

@section('title', __('schedule.print_title'))
@section('ownSheet', '1')

@section('content')
    <div class="sheet">
        <header class="sheet__head">
            <x-ui.logo class="sheet__logo" />

            <div class="sheet__meta">
                <h1 class="sheet__title">{{ __('schedule.print_title') }}</h1>

                @if ($cohortName !== null)
                    <p class="sheet__cohort">{{ $cohortName }}</p>
                @endif

                <p class="sheet__printed">
                    {{ __('schedule.printed_at', ['at' => \App\Support\Dates::dateTime($serverNow)]) }}
                </p>
            </div>
        </header>

        @if ($sessions->isEmpty())
            {{-- The empty state matters more on paper: a blank sheet reads as a
                 printing fault, not as an empty timetable (art. 17). --}}
            <x-ui.empty-state
                icon="cal"
                :title="__('schedule.empty_title')"
                :description="__('schedule.empty_body')"
            />
        @else
            <table class="sheet__table">
                <caption class="sheet__caption">{{ __('schedule.table_caption') }}</caption>

                <thead>
                    <tr>
                        <th scope="col">{{ __('schedule.col_date') }}</th>
                        <th scope="col">{{ __('schedule.col_time') }}</th>
                        <th scope="col">{{ __('schedule.col_title') }}</th>
                        <th scope="col">{{ __('schedule.col_topic') }}</th>
                        <th scope="col">{{ __('schedule.col_trainer') }}</th>
                        <th scope="col">{{ __('schedule.col_status') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($sessions as $session)
                        <tr @class(['sheet__row', 'sheet__row--cancelled' => $session->isCancelled])>
                            <td class="sheet__date">{{ \App\Support\Dates::longDate($session->startsAt) }}</td>
                            <td class="sheet__time">
                                {{ \App\Support\Dates::timeRange($session->startsAt, $session->endsAt) }}
                            </td>
                            <td>{{ $session->title }}</td>
                            <td>{{ $session->topic }}</td>
                            <td>{{ $session->trainerName ?? \App\Support\Dates::ABSENT }}</td>
                            <td>
                                {{ $session->statusLabel }}

                                @if ($session->isCancelled && $session->cancellationReason !== null)
                                    <span class="sheet__reason">{{ $session->cancellationReason }}</span>
                                @endif

                                @if ($session->replacementStartsAt !== null)
                                    <span class="sheet__reason">
                                        {{ __('schedule.replacement_at', [
                                            'at' => \App\Support\Dates::dateTime($session->replacementStartsAt),
                                        ]) }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <footer class="sheet__foot">
            <p>{{ __('schedule.timezone_note') }}</p>
            <p>{{ __('landing.footer.rights') }}</p>
        </footer>
    </div>
@endsection
