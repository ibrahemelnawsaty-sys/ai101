{{--
    Programme directory.
    Public, indexable, linked from the footer.

    Only published programmes reach this page, and the filter is a query scope
    in the controller — never a condition here. A template that decided what a
    visitor may see would be one forgotten `@if` away from leaking a draft
    (Constitution art. 5).

    @see BR-31 · Constitution art. 5, 15, 17 · D-39

    Variables from App\Http\Controllers\Public\ProgramDirectoryController@index:
      $programs     list    [['name','name_en','slug','description','banner_url',
                              'open_cohorts','objectives'[],'audience'[]]]
      $screen       string  'programs'
      $screenState  string  normal | loading | empty | error
--}}
@extends('layouts.public')

@section('content')

    <section class="sec">
        <div class="wrap">
            <div class="sec__hd sec__hd--center">
                <h1 class="h2--display">{{ __('pages.programs.title') }}</h1>
                <p class="lead">{{ __('pages.programs.lead') }}</p>
            </div>

            @if ($screenState === 'error')

                <x-ui.empty-state
                    variant="error"
                    icon="i-warn"
                    :title="__('pages.programs.error_title')"
                    :description="__('pages.programs.error_body')">
                    <x-slot:action>
                        <x-ui.button variant="secondary" :href="url()->current()">
                            {{ __('pages.programs.error_action') }}
                        </x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>

            @elseif ($screenState === 'loading')

                {{-- Shaped like the cards it replaces, not a spinner (art. 17). --}}
                <div class="grid g2">
                    <x-ui.skeleton shape="card" :label="__('landing.states.loading_label')"/>
                    <x-ui.skeleton shape="card"/>
                </div>

            @else

                @forelse ($programs as $program)
                    @if ($loop->first)<div class="grid g2">@endif

                    <article class="card">
                        @if (filled(data_get($program, 'banner_url')))
                            <img class="card__banner"
                                 src="{{ data_get($program, 'banner_url') }}"
                                 alt=""
                                 loading="lazy"
                                 decoding="async">
                        @endif

                        <h2>{{ data_get($program, 'name') }}</h2>

                        @if (filled(data_get($program, 'name_en')))
                            <p class="card__sub" dir="ltr">{{ data_get($program, 'name_en') }}</p>
                        @endif

                        @if (filled(data_get($program, 'description')))
                            <p>{{ data_get($program, 'description') }}</p>
                        @endif

                        <p class="pill">
                            {{ trans_choice('pages.programs.cohorts_choice', (int) data_get($program, 'open_cohorts', 0), ['count' => data_get($program, 'open_cohorts', 0)]) }}
                        </p>

                        @if (filled(data_get($program, 'objectives')))
                            <h3>{{ __('pages.programs.objectives_label') }}</h3>
                            <ul class="legal__list">
                                @foreach (data_get($program, 'objectives') as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if (filled(data_get($program, 'audience')))
                            <h3>{{ __('pages.programs.audience_label') }}</h3>
                            <ul class="legal__list">
                                @foreach (data_get($program, 'audience') as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- One programme has a landing page today, so every card
                             points at it. When a per-programme route exists this
                             becomes route('program.show', $slug) and nothing else
                             on the page changes. --}}
                        <x-ui.button variant="primary" :href="route('home')">
                            {{ __('pages.programs.open_action') }}
                        </x-ui.button>
                    </article>

                    @if ($loop->last)</div>@endif
                @empty
                    <x-ui.empty-state
                        icon="i-folder"
                        :title="__('pages.programs.empty_title')"
                        :description="__('pages.programs.empty_body')">
                        <x-slot:action>
                            <x-ui.button variant="secondary" :href="route('contact')">
                                {{ __('pages.programs.empty_action') }}
                            </x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @endforelse

            @endif

            <p class="legal__back">
                <a href="{{ route('home') }}">{{ __('pages.shared.back_home') }}</a>
            </p>
        </div>
    </section>
@endsection
