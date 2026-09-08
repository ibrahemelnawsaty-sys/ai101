{{--
    Live sessions. The meeting URL is NEVER placed in this document: the join button
    posts to a guarded endpoint that re-checks the window (S-15m .. E) and returns the
    URL only then. Inspecting the page source before the window reveals nothing.

    @see PRD §9.10 · BR-07
--}}
@extends('layouts.app')

@section('title', __('nav.live'))
@section('subtitle', __('live.subtitle'))

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('live.error_title')"
            :description="__('live.error_body')"
            :action-label="__('app.retry')" :action-href="route('live')" />
    @else
        {{-- Featured upcoming session ---------------------------------------- --}}
        @if (is_null($featured))
            <x-ui.card>
                <x-ui.skeleton height="var(--s5)" width="55%" />
                <x-ui.skeleton height="var(--s3)" width="42%" class="u-mt-2" />
                <x-ui.skeleton height="var(--touch-min)" width="var(--d-3)" class="u-mt-4" />
            </x-ui.card>
        @elseif ($featured->isMissing)
            <x-ui.empty-state icon="video"
                :title="__('live.empty_title')"
                :description="__('live.empty_body')"
                :action-label="__('nav.schedule')" :action-href="route('schedule')" />
        @else
            <x-ui.card class="dc--span" icon="video" :title="__('live.next_title')">
                <x-slot:action>
                    <x-ui.pill :variant="$featured->statusVariant">{{ $featured->statusLabel }}</x-ui.pill>
                </x-slot:action>

                <div class="row row--plain">
                    <div class="row__m">
                        <b>{{ $featured->topic }}</b>
                        <span>
                            <span class="u-num">{{ \App\Support\Dates::longDate($featured->startsAt) }}</span>
                            · <span class="u-num">{{ \App\Support\Dates::timeRange12($featured->startsAt, $featured->endsAt) }}</span>
                        </span>
                        <span class="row__by">
                            <x-ui.avatar size="sm" :name="$featured->trainerName" />
                            {{ $featured->trainerName }}
                        </span>
                    </div>
                    <div class="row__e">
                        <x-ui.countdown :until="$featured->startsAt" :server-now="$serverNow"
                            :label="__('live.starts_in')" />
                    </div>
                </div>

                {{-- A plain form, not an Alpine handler. The button used to call
                     atharJoinSession(), a component that existed in this one line and
                     nowhere else in the codebase: Alpine could not resolve it, the
                     click did nothing, and live.join had no caller at all — BR-24 was
                     unreachable from the interface.

                     A form is also the better shape. join() answers with a redirect —
                     away() to the meeting, or back() with an error — and a browser
                     follows a redirect natively. The JavaScript would have had to
                     re-implement that, and it would break again the day JS fails. --}}
                <div class="row__acts">
                    <form method="POST" action="{{ route('live.join', $featured->id) }}">
                        @csrf
                        <x-ui.button variant="primary"
                            type="submit"
                            :disabled="! $featured->joinWindowOpen">{{ __('live.join') }}</x-ui.button>
                    </form>

                    @unless ($featured->joinWindowOpen)
                        <p class="hint">
                            <x-ui.icon name="lock" />
                            {{ __('live.join_locked', ['minutes' => $featured->joinOpensBeforeMinutes]) }}
                        </p>
                    @endunless

                    @error('session')
                        <p class="hint hint--error" role="alert">
                            <x-ui.icon name="warn" />
                            {{ $message }}
                        </p>
                    @enderror

                    {{-- The passcode arrives with the URL, only after the guarded call succeeds. --}}
                    <template x-if="passcode">
                        <div class="live__pass">
                            <span>{{ __('live.passcode') }}</span>
                            <code dir="ltr" x-text="passcode"></code>
                            <x-ui.button variant="secondary" size="sm" type="button"
                                x-on:click="copyPasscode()">{{ __('app.copy') }}</x-ui.button>
                        </div>
                    </template>
                </div>
            </x-ui.card>
        @endif

        <div class="dgrid u-mt-4">
            {{-- Upcoming list ------------------------------------------------- --}}
            <x-ui.card icon="cal" :title="__('live.upcoming_title')">
                @if (is_null($upcoming))
                    @for ($i = 0; $i < 3; $i++)
                        <div class="row row--sk">
                            <div class="row__m">
                                <x-ui.skeleton height="var(--s4)" width="62%" />
                                <x-ui.skeleton height="var(--s3)" width="44%" class="u-mt-1" />
                            </div>
                        </div>
                    @endfor
                @elseif ($upcoming->isEmpty())
                    <x-ui.empty-state icon="cal" size="sm"
                        :title="__('live.upcoming_empty_title')"
                        :description="__('live.upcoming_empty_body')" />
                @else
                    @foreach ($upcoming as $session)
                        <div class="row">
                            <div class="row__m">
                                <b>{{ $session->topic }}</b>
                                <span class="u-num">{{ \App\Support\Dates::dateTime($session->startsAt) }}</span>
                            </div>
                            <div class="row__e">
                                <x-ui.pill :variant="$session->statusVariant">{{ $session->statusLabel }}</x-ui.pill>
                            </div>
                        </div>
                    @endforeach
                @endif
            </x-ui.card>

            {{-- Past recordings ------------------------------------------------ --}}
            <x-ui.card class="dc--2" icon="folder" :title="__('live.recordings_title')">
                <form method="GET" action="{{ route('live') }}" class="toolbar__filters">
                    <x-ui.search-input name="q" :value="request('q')" :placeholder="__('live.search_recordings')" />
                    <x-ui.select name="week" :label="__('schedule.filter_week')" :options="$weekOptions" :value="request('week')" />
                    <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
                </form>

                @if (is_null($recordings))
                    @for ($i = 0; $i < 4; $i++)
                        <div class="row row--sk">
                            <div class="row__m">
                                <x-ui.skeleton height="var(--s4)" width="58%" />
                                <x-ui.skeleton height="var(--s3)" width="38%" class="u-mt-1" />
                            </div>
                            <x-ui.skeleton height="var(--s9)" width="var(--s22)" />
                        </div>
                    @endfor
                @elseif ($recordings->isEmpty())
                    <x-ui.empty-state icon="video" size="sm"
                        :title="__('live.recordings_empty_title')"
                        :description="__('live.recordings_empty_body')" />
                @else
                    @foreach ($recordings as $recording)
                        <div class="row">
                            <div class="row__m">
                                <b>{{ $recording->topic }}</b>
                                <span>
                                    <span class="u-num">{{ \App\Support\Dates::shortDate($recording->startsAt) }}</span>
                                    · <span class="u-num">{{ $recording->durationMinutes }}</span> {{ trans_choice('app.minutes', $recording->durationMinutes) }}
                                </span>
                            </div>
                            <div class="row__e">
                                <x-ui.button variant="secondary" size="sm"
                                    :href="route('live.recording', $recording->id)">{{ __('live.watch') }}</x-ui.button>
                            </div>
                        </div>
                    @endforeach

                    <x-ui.pagination :paginator="$recordings" />
                @endif
            </x-ui.card>
        </div>
    @endif
@endsection
