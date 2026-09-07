{{--
    Preview-mode banner.

    @see BR-33, BR-34, BR-35 · PRD §9.18 · CONSTITUTION Article 23

    Preview is the strongest permission on the platform, so this bar is loud on
    purpose: burnt orange, sticky at the top of the document, and the button
    that ends the preview is ALWAYS visible — it is never behind a menu, never
    scrolled away, and never disabled.

    WHAT THIS COMPONENT IS NOT
    It is not the enforcement. Read-only is enforced in the data layer by
    App\Http\Middleware\ImpersonationReadOnly, which rejects every unsafe method
    whether or not this bar was ever rendered (Article 5, BR-33). Removing this
    markup would change nothing about what a previewing administrator can do.

    The countdown is anchored to Clock::now(); the browser clock is never
    trusted (BR-07). When it reaches zero the form is submitted so the SERVER
    ends the session and says so — the browser does not decide it is over.

    Shared by the middleware as `$impersonation`:
      ['target_id' => string, 'admin_id' => string, 'remaining_seconds' => int]
--}}

@if ($isActive)
    {{-- data-impersonation-bar is the machine-readable proof that the banner is
         on the page, so "the bar is visible for the whole preview" is asserted
         rather than eyeballed (Article 23). --}}
    <div class="impbar"
         data-impersonation-bar
         role="alert"
         aria-live="assertive"
         x-data="impersonation({ endsAt: @js($endsAt) })">

        <svg aria-hidden="true"><use href="#i-eye"></use></svg>

        <p class="impbar__msg">
            {{ __('admin.impersonation.banner', ['name' => $targetName]) }}
        </p>

        {{-- The remaining time is announced politely so it does not interrupt
             a screen reader every single second. --}}
        <p class="impbar__timer" aria-live="polite">
            {{ __('admin.impersonation.ends_in', ['countdown' => '']) }}
            <span class="u-num" x-text="label">--:--</span>
        </p>

        <form method="POST"
              action="{{ $stopUrl }}"
              x-ref="stop">
            @csrf
            @method('DELETE')
            <button type="submit" class="impbar__stop">
                <svg aria-hidden="true"><use href="#i-x"></use></svg>
                <span>{{ __('admin.impersonation.stop') }}</span>
            </button>
        </form>
    </div>
@endif
