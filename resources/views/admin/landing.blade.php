{{--
    Admin · landing page settings (BR-31).

    Everything a visitor reads on the public page is edited here: the switch that
    opens and closes registration, the remaining-seats figure and its manual
    override, the countdown, the marketing texts and the FAQ list. None of it is
    written in code, and none of it is written in a template.

    The FAQ editor works without JavaScript: existing entries are rendered as
    ordinary form rows, and a new entry is added by submitting the add form. No
    hidden client-side state, so nothing can be lost silently.

    Four states: error · loading skeleton shaped like the form · empty (no FAQ
    entries yet) · normal.

    @see PRD §9.1, §9.18 · BR-27, BR-31, BR-36
--}}
@extends('layouts.app')

@section('title', __('admin.landing.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.landing.edit')" />
    @elseif (is_null($settings))
        <x-ui.card class="dc--span">
            <x-ui.skeleton height="var(--s5)" width="var(--d-3)" />
            <x-ui.skeleton height="var(--touch-min)" width="100%" class="u-mt-4" />
            <x-ui.skeleton height="var(--touch-min)" width="100%" class="u-mt-2" />
            <x-ui.skeleton height="var(--d-1)" width="100%" class="u-mt-4" />
        </x-ui.card>
    @else

        {{-- Registration and seats ------------------------------------------------- --}}
        <x-ui.card class="dc--span" icon="globe" :title="__('admin.landing.title')">
            <x-slot:action>
                <a href="{{ route('home') }}" target="_blank" rel="noopener">{{ __('app.view_details') }}</a>
            </x-slot:action>

            <form method="POST" action="{{ route('admin.landing.update') }}">
                @csrf
                @method('PUT')

                <x-ui.toggle name="is_registration_open" value="1"
                    :checked="old('is_registration_open', $settings->isRegistrationOpen)"
                    :label="__('admin.landing.registration_open')" />

                <x-ui.toggle name="countdown_enabled" value="1"
                    :checked="old('countdown_enabled', $settings->countdownEnabled)"
                    :label="__('admin.landing.countdown_enabled')" />

                <div class="f2">
                    <x-ui.input name="seats_remaining" type="number" min="0" step="1" readonly
                        :label="__('admin.landing.seats_remaining')"
                        :hint="__('admin.landing.seats_computed_hint')"
                        :value="$settings->seatsRemaining" />
                    <x-ui.input name="seats_override" type="number" min="0" step="1"
                        :label="__('admin.landing.seats_override')"
                        :hint="__('admin.landing.seats_override_hint')"
                        :value="old('seats_override', $settings->seatsOverride)" />
                </div>

                <h3 class="abrief__sub">{{ __('admin.landing.texts') }}</h3>

                <x-ui.input name="hero_title" required :label="__('admin.landing.hero_title')"
                    :value="old('hero_title', $settings->heroTitle)" />

                <x-ui.textarea name="hero_subtitle" rows="3"
                    :label="__('admin.landing.hero_subtitle')"
                    :value="old('hero_subtitle', $settings->heroSubtitle)" />

                <x-ui.textarea name="about_body" rows="5"
                    :label="__('admin.landing.about_body')"
                    :value="old('about_body', $settings->aboutBody)" />

                <div class="row__acts">
                    <x-ui.button variant="primary" type="submit">{{ __('app.save_changes') }}</x-ui.button>
                </div>

                <p class="footnote">{{ __('admin.landing.saved_hint') }}</p>
            </form>
        </x-ui.card>

        {{-- FAQ ---------------------------------------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="chat" :title="__('admin.landing.faq')">
            @if ($settings->faq->isEmpty())
                <x-ui.empty-state icon="chat"
                    :title="__('admin.landing.faq_empty_title')"
                    :description="__('admin.landing.faq_empty_body')" />
            @else
                @foreach ($settings->faq as $entry)
                    <form method="POST" action="{{ route('admin.landing.faq.update', $entry->id) }}">
                        @csrf
                        @method('PUT')

                        <x-ui.input name="question" required
                            :label="__('admin.landing.faq_question')"
                            :value="old('question', $entry->question)" />

                        <x-ui.textarea name="answer" rows="3" required
                            :label="__('admin.landing.faq_answer')"
                            :value="old('answer', $entry->answer)" />

                        <div class="row__acts">
                            <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.save_changes') }}</x-ui.button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('admin.landing.faq.destroy', $entry->id) }}">
                        @csrf
                        @method('DELETE')
                        <div class="row__acts">
                            <x-ui.button variant="ghost" size="sm" type="submit">{{ __('app.delete') }}</x-ui.button>
                        </div>
                    </form>
                @endforeach
            @endif

            <h3 class="abrief__sub">{{ __('admin.landing.faq_add') }}</h3>

            <form method="POST" action="{{ route('admin.landing.faq.store') }}">
                @csrf

                <x-ui.input name="question" required
                    :label="__('admin.landing.faq_question')" :value="old('question')" />

                <x-ui.textarea name="answer" rows="3" required
                    :label="__('admin.landing.faq_answer')" :value="old('answer')" />

                <div class="row__acts">
                    <x-ui.button variant="primary" size="sm" type="submit">{{ __('admin.landing.faq_add') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif
@endsection
