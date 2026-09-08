{{--
    Contact channels.
    Public, indexable, linked from the footer.

    NO FORM, AND THAT IS THE POINT. `D-02` is open, no mail provider is
    approved, and MAIL_MAILER discards every message: a form here would take a
    visitor's question and drop it in silence — the worst failure a contact page
    can have, because the visitor believes they have been heard. The channels
    below leave the platform entirely and arrive with certainty.

    Every address and number comes from config (BR-36). Nothing below is a
    literal, so a changed number changes in one place.

    @see BR-36 · Constitution art. 15, 17 · D-02, D-39

    Variables from App\Http\Controllers\Public\PageController@contact:
      $whatsapp     string  digits only, international, no '+' — pasted into wa.me
      $email        string
      $screen       string  'contact'
      $screenState  string  normal | loading | empty | error
--}}
@extends('layouts.public')

@section('content')
    @include('partials.public-header')

    <section class="sec">
        <div class="wrap wrap--reading">
            <div class="sec__hd">
                <h1>{{ __('pages.contact.title') }}</h1>
                <p class="lead">{{ __('pages.contact.lead') }}</p>
            </div>

            @if ($screenState === 'empty')

                {{-- No channel configured at all. That is a deployment fault,
                     not "nothing to show", so it says what to do next. --}}
                <x-ui.empty-state
                    variant="error"
                    icon="i-warn"
                    :title="__('pages.about.error_title')"
                    :description="__('pages.about.error_body')">
                    <x-slot:action>
                        <x-ui.button variant="secondary" :href="route('home')">
                            {{ __('pages.shared.back_home') }}
                        </x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>

            @else

                <div class="grid g2">
                    @if (filled($whatsapp))
                        <div class="card">
                            <div class="card__ic"><svg aria-hidden="true"><use href="#i-wa"/></svg></div>
                            <h2>{{ __('pages.contact.whatsapp_label') }}</h2>
                            <p>{{ __('pages.contact.whatsapp_hint') }}</p>
                            {{-- dir="ltr" on the number itself; the label around it stays RTL. --}}
                            <p class="u-num" dir="ltr">{{ $whatsapp }}</p>
                            <x-ui.button
                                variant="primary"
                                :href="'https://wa.me/'.$whatsapp.'?text='.rawurlencode(__('pages.contact.whatsapp_prefill'))">
                                {{ __('pages.contact.whatsapp_action') }}
                            </x-ui.button>
                        </div>
                    @endif

                    @if (filled($email))
                        <div class="card">
                            <div class="card__ic"><svg aria-hidden="true"><use href="#i-mail"/></svg></div>
                            <h2>{{ __('pages.contact.email_label') }}</h2>
                            <p>{{ __('pages.contact.email_hint') }}</p>
                            <p dir="ltr">{{ $email }}</p>
                            <x-ui.button variant="secondary" :href="'mailto:'.$email">
                                {{ __('pages.contact.email_action') }}
                            </x-ui.button>
                        </div>
                    @endif
                </div>

                <div class="card">
                    <h2>{{ __('pages.contact.faq_label') }}</h2>
                    <p>{{ __('pages.contact.faq_hint') }}</p>
                    <x-ui.button variant="secondary" :href="route('home').'#faq'">
                        {{ __('pages.contact.faq_action') }}
                    </x-ui.button>
                </div>

                {{-- Remove this block the day D-02 closes and a form exists. --}}
                <p class="sec__empty">
                    <strong>{{ __('pages.contact.form_unavailable_title') }}</strong>
                    {{ __('pages.contact.form_unavailable_body') }}
                </p>

            @endif

            <p class="legal__back">
                <a href="{{ route('home') }}">{{ __('pages.shared.back_home') }}</a>
            </p>
        </div>
    </section>
@endsection
