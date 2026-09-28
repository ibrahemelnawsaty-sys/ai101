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

    ACCESSIBILITY (D-127). The bar used to be one assertive live region with a
    ticking timer inside it, so a screen reader was interrupted every second for
    the whole preview. Now: the bar is a labelled REGION; the sentence that names
    the account is the only alert, and it never changes; the ticking digits sit
    in no live region at all (they are read when the reader navigates to them);
    and a separate polite region says something only twice — at five minutes and
    at one minute left. The stop button is unchanged, always visible (Art. 23).

    Shared by the middleware as `$impersonation`:
      ['target_id' => string, 'admin_id' => string, 'remaining_seconds' => int]
--}}

@if ($isActive)
    {{-- data-impersonation-bar is the machine-readable proof that the banner is
         on the page, so "the bar is visible for the whole preview" is asserted
         rather than eyeballed (Article 23). --}}
    <div class="impbar"
         data-impersonation-bar
         role="region"
         aria-label="{{ __('admin.impersonation.region') }}"
         x-data="impersonation({
             endsAt: @js($endsAt),
             fiveMinutes: @js(__('admin.impersonation.five_minutes_left')),
             oneMinute: @js(__('admin.impersonation.one_minute_left'))
         })">

        <x-ui.icon name="eye" />

        <p class="impbar__msg" role="alert">
            {{ __('admin.impersonation.banner', ['name' => $targetName]) }}
        </p>

        <p class="impbar__timer">
            {{ __('admin.impersonation.ends_in', ['countdown' => '']) }}
            <span class="u-num" x-text="label">--:--</span>
        </p>

        {{-- Says something only when the state changes in a way that matters. --}}
        <p class="ui-sr" role="status" aria-live="polite" x-text="announcement"></p>

        {{-- Ending a preview is never held back by the double-submit guard (Article 23). --}}
        <form method="POST"
              action="{{ $stopUrl }}"
              data-submit-guard="off"
              x-ref="stop">
            @csrf
            @method('DELETE')
            <button type="submit" class="impbar__stop">
                <x-ui.icon name="x" />
                <span>{{ __('admin.impersonation.stop') }}</span>
            </button>
        </form>
    </div>
@endif
