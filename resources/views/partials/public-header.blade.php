{{--
    Sticky translucent public header. Logo sits on the right (RTL), navigation in the
    middle, a secondary «login» and a primary «register» on the left.
    The links collapse into a disclosure panel below 1024px so they stay reachable
    by keyboard and by screen reader on a phone.

    @see PRD §9.1.1 · Constitution art. 16, 18
--}}
<header class="nav">
    <div class="nav__in">
        <a class="logo" href="{{ route('home') }}" aria-label="{{ __('landing.meta.brand_alt') }}">
            <svg viewBox="0 0 242.186 102.814" fill="currentColor" role="img" aria-label="{{ __('landing.meta.brand_alt') }}"><use href="#athar-wordmark"/></svg>
        </a>

        <nav class="nav__links" id="navLinks" aria-label="{{ __('landing.meta.page_sections') }}">
            <a href="{{ route('home') }}#about">{{ __('landing.nav.about') }}</a>
            <a href="{{ route('home') }}#lab">{{ __('landing.nav.lab') }}</a>
            <a href="{{ route('home') }}#learn">{{ __('landing.nav.learn') }}</a>
            <a href="{{ route('home') }}#certs">{{ __('landing.nav.certificates') }}</a>
            <a href="{{ route('home') }}#faq">{{ __('landing.nav.faq') }}</a>
        </nav>

        <div class="nav__cta">
            <x-ui.button variant="ghost" size="sm" :href="route('login')">
                {{ __('landing.nav.login') }}
            </x-ui.button>

            <x-ui.button variant="primary" size="sm" :href="route('register')" class="mag">
                <span class="mag__t">{{ __('landing.nav.register') }}</span>
            </x-ui.button>

            <button type="button"
                    class="nav__burger"
                    id="navBurger"
                    aria-controls="navLinks"
                    aria-expanded="false"
                    aria-label="{{ __('landing.meta.open_menu') }}"
                    data-label-open="{{ __('landing.meta.open_menu') }}"
                    data-label-close="{{ __('landing.meta.close_menu') }}">
                <span class="nav__burger-bar" aria-hidden="true"></span>
                <span class="nav__burger-bar" aria-hidden="true"></span>
                <span class="nav__burger-bar" aria-hidden="true"></span>
            </button>
        </div>
    </div>
</header>
