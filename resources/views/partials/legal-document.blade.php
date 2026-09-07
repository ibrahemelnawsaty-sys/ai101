{{--
    Shared body of the two legal pages (terms, privacy).
    The text is admin-managed content (BR-31) delivered as STRUCTURED data, never as
    raw HTML — nothing here is rendered unescaped, so a compromised admin field cannot
    inject markup (Constitution art. 24).

    @see BR-31 · Constitution art. 17, 24

    Variables:
      $documentTitle  string  page heading, from lang (chrome)
      $document       array   ['updated_at' => string|null,
                               'sections'   => [['heading' => string,
                                                 'paragraphs' => string[],
                                                 'items' => string[]]]]
      $state          string  'ok' | 'loading' | 'empty' | 'error'
--}}
<article class="legal">
    <header class="legal__hd">
        <h1>{{ $documentTitle }}</h1>
        @if (filled(data_get($document ?? [], 'updated_at')))
            <p class="legal__updated">
                {{ __('landing.legal.updated_at') }}:
                <span class="u-num">{{ data_get($document, 'updated_at') }}</span>
            </p>
        @endif
    </header>

    @if (($state ?? 'ok') === 'error')

        <x-ui.empty-state
            variant="error"
            icon="i-warn"
            :title="__('landing.legal.error_title')"
            :description="__('landing.legal.error_body')">
            <x-ui.button variant="secondary" :href="url()->current()">
                {{ __('landing.states.error_action') }}
            </x-ui.button>
        </x-ui.empty-state>

    @elseif (($state ?? 'ok') === 'loading')

        <div class="legal__body">
            <x-ui.skeleton shape="title" :label="__('landing.states.loading_label')"/>
            <x-ui.skeleton shape="text" :lines="2"/>
            <x-ui.skeleton shape="text" :lines="2"/>
            <x-ui.skeleton shape="text" :lines="1"/>
            <x-ui.skeleton shape="title"/>
            <x-ui.skeleton shape="text" :lines="2"/>
            <x-ui.skeleton shape="text" :lines="1"/>
        </div>

    @else

        @forelse (data_get($document ?? [], 'sections', []) as $section)
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
                :title="__('landing.legal.empty_title')"
                :description="__('landing.legal.empty_body')">
                <x-ui.button variant="secondary" :href="'mailto:'.config('athar.email')">
                    {{ __('landing.states.empty_page_action') }}
                </x-ui.button>
            </x-ui.empty-state>
        @endforelse

    @endif

    <p class="legal__back">
        <a href="{{ route('home') }}">{{ __('landing.legal.back_home') }}</a>
    </p>
</article>
