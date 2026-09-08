{{--
    About the centre.
    Public, indexable, linked from the footer.

    Reuses the reading column and the `legal` type scale rather than inventing a
    second long-form layout: it is the same job — a heading, a lead, and a few
    sections of prose — and a second set of styles for it would drift from this
    one within a release.

    The copy is delivered as structured data and printed escaped; nothing here
    is ever raw HTML (Constitution art. 24).

    @see BR-31 · Constitution art. 15, 17, 24 · D-14, D-39

    Variables from App\Http\Controllers\Public\PageController@about:
      $sections     list    [['heading' => string, 'paragraphs' => string[], 'items' => string[]]]
      $screen       string  'about'
      $screenState  string  normal | loading | empty | error
--}}
@extends('layouts.public')

@section('content')

    <section class="sec sec--legal">
        <div class="wrap wrap--reading">
            <article class="legal">
                <header class="legal__hd">
                    <h1>{{ __('pages.about.title') }}</h1>
                    <p class="lead">{{ __('pages.about.lead') }}</p>
                </header>

                @if ($screenState === 'error')

                    <x-ui.empty-state
                        variant="error"
                        icon="i-warn"
                        :title="__('pages.about.error_title')"
                        :description="__('pages.about.error_body')">
                        <x-slot:action>
                            <x-ui.button variant="secondary" :href="url()->current()">
                                {{ __('landing.states.error_action') }}
                            </x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>

                @elseif ($screenState === 'loading')

                    <div class="legal__body">
                        <x-ui.skeleton shape="title" :label="__('landing.states.loading_label')"/>
                        <x-ui.skeleton shape="text" :lines="3"/>
                        <x-ui.skeleton shape="title"/>
                        <x-ui.skeleton shape="text" :lines="2"/>
                    </div>

                @else

                    @forelse ($sections as $section)
                        @if ($loop->first)<div class="legal__body">@endif

                        <section class="legal__sec">
                            @if (filled(data_get($section, 'heading')))
                                <h2>{{ data_get($section, 'heading') }}</h2>
                            @endif

                            @foreach (data_get($section, 'paragraphs', []) as $paragraph)
                                <p>{{ $paragraph }}</p>
                            @endforeach

                            @if (filled(data_get($section, 'items')))
                                <ul class="legal__list">
                                    @foreach (data_get($section, 'items') as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>

                        @if ($loop->last)</div>@endif
                    @empty
                        <x-ui.empty-state
                            icon="i-file"
                            :title="__('pages.about.empty_title')"
                            :description="__('pages.about.empty_body')">
                            <x-slot:action>
                                <x-ui.button variant="secondary" :href="route('contact')">
                                    {{ __('pages.contact.title') }}
                                </x-ui.button>
                            </x-slot:action>
                        </x-ui.empty-state>
                    @endforelse

                @endif

                <p class="legal__back">
                    <a href="{{ route('home') }}">{{ __('pages.shared.back_home') }}</a>
                </p>
            </article>
        </div>
    </section>
@endsection
