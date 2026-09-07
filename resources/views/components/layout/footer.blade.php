{{--
    Dashboard footer: quiet, one line, no marketing.

    @see PRD §9.5.2 · CONSTITUTION Articles 15, 16 · BR-36

    The year is printed as a plain integer. A thousands separator here would
    render «2,026», which is the bug this comment exists to prevent.
    The zone note is fixed platform-wide: storage is UTC, display is Riyadh.
--}}

<footer class="foot foot--app">
    <div class="foot__bot">
        <span>
            © <span class="u-num">{{ $year }}</span>
            {{ __('landing.footer.rights') }}
        </span>

        <span class="foot__zone">{{ __('nav.chrome.footer_note') }}</span>

        {{-- A route the router has not registered yet is dropped rather than
             thrown: the chrome must never take a whole screen down (Article 7). --}}
        <nav class="foot__links" aria-label="{{ __('landing.footer.quick_links') }}">
            @if ($termsUrl !== null)
                <a href="{{ $termsUrl }}">{{ __('landing.footer.terms') }}</a>
            @endif
            @if ($privacyUrl !== null)
                <a href="{{ $privacyUrl }}">{{ __('landing.footer.privacy') }}</a>
            @endif
            <a href="mailto:{{ $email }}" dir="ltr">{{ $email }}</a>
        </nav>
    </div>
</footer>
