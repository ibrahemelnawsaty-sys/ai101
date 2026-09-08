{{--
    Public footer. Shared by the landing page and the legal pages.
    The descriptive paragraph and the tagline are admin-managed content (BR-31);
    the labels are interface chrome and come from lang/ar/landing.php.

    @see BR-31, BR-36 · PRD §9.1.1
--}}
<footer class="foot">
    <div class="wrap">
        <div class="foot__grid">
            <div>
                <span class="logo">
                    <svg viewBox="0 0 242.186 102.814" fill="currentColor" role="img" aria-label="{{ __('landing.meta.brand_alt') }}"><use href="#athar-wordmark"/></svg>
                </span>

                @if (filled(data_get($landing ?? [], 'footer.about')))
                    <p class="foot__about">{{ data_get($landing, 'footer.about') }}</p>
                @endif

                @if (filled(data_get($landing ?? [], 'footer.tagline')))
                    <p class="foot__tag">{{ data_get($landing, 'footer.tagline') }}</p>
                @endif
            </div>

            <nav aria-labelledby="foot-links">
                <h4 id="foot-links">{{ __('landing.footer.quick_links') }}</h4>
                <ul>
                    <li><a href="{{ route('about') }}">{{ __('pages.about.title') }}</a></li>
                    <li><a href="{{ route('programs') }}">{{ __('pages.programs.title') }}</a></li>
                    <li><a href="{{ route('home') }}#about">{{ __('landing.nav.about') }}</a></li>
                    <li><a href="{{ route('home') }}#lab">{{ __('landing.nav.lab') }}</a></li>
                    <li><a href="{{ route('home') }}#learn">{{ __('landing.nav.learn') }}</a></li>
                    <li><a href="{{ route('home') }}#certs">{{ __('landing.nav.certificates') }}</a></li>
                    <li><a href="{{ route('home') }}#faq">{{ __('landing.nav.faq') }}</a></li>
                </ul>
            </nav>

            <nav aria-labelledby="foot-contact">
                <h4 id="foot-contact">{{ __('landing.footer.contact') }}</h4>
                <ul>
                    <li><a href="mailto:{{ config('athar.email') }}" dir="ltr">{{ config('athar.email') }}</a></li>
                    <li>
                        <a href="https://wa.me/{{ config('athar.whatsapp') }}" target="_blank" rel="noopener" dir="ltr">
                            +{{ config('athar.whatsapp') }}
                        </a>
                    </li>
                    <li><a href="{{ route('contact') }}">{{ __('pages.contact.title') }}</a></li>
                    <li><a href="{{ route('terms') }}">{{ __('landing.footer.terms') }}</a></li>
                    <li><a href="{{ route('privacy') }}">{{ __('landing.footer.privacy') }}</a></li>
                </ul>
            </nav>
        </div>

        <div class="foot__bot">
            {{-- The year is a plain integer: a thousands separator here would render «2,026». --}}
            <span>© <span class="u-num">{{ $copyrightYear ?? '' }}</span> {{ __('landing.footer.rights') }}</span>
            <span dir="ltr">{{ config('athar.domain') }}</span>
        </div>
    </div>
</footer>
