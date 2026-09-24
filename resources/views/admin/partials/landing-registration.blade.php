{{--
    The cohort's registration block inside the content editor (D-114): the
    registration switch, the countdown switch, the computed seat figure and the
    manual override. Part of the same draft as every text, published with it.

    The switch is still re-read by the registration path on every attempt, so
    closing it closes the form on the next request after publishing (Art. 5).

    @see BR-31 · PRD §9.1.2, §9.18 · D-114
--}}
<div class="le-settings">
    <label class="ui-switch" for="le-registration-open">
        <span class="ui-switch__control">
            <input type="checkbox" role="switch" class="ui-switch__input" id="le-registration-open"
                   x-bind:checked="setting('is_registration_open')"
                   x-bind:aria-checked="setting('is_registration_open').toString()"
                   x-on:change="setSetting('is_registration_open', $event.target.checked)">
            <span class="ui-switch__track" aria-hidden="true"><span class="ui-switch__thumb"></span></span>
        </span>
        <span class="ui-check__text"><span class="ui-check__title">{{ __('admin.landing.registration_open') }}</span></span>
    </label>

    <label class="ui-switch" for="le-countdown">
        <span class="ui-switch__control">
            <input type="checkbox" role="switch" class="ui-switch__input" id="le-countdown"
                   x-bind:checked="setting('countdown_enabled')"
                   x-bind:aria-checked="setting('countdown_enabled').toString()"
                   x-on:change="setSetting('countdown_enabled', $event.target.checked)">
            <span class="ui-switch__track" aria-hidden="true"><span class="ui-switch__thumb"></span></span>
        </span>
        <span class="ui-check__text"><span class="ui-check__title">{{ __('admin.landing.countdown_enabled') }}</span></span>
    </label>

    <div class="le-settings__pair">
        <div class="ui-field">
            <label class="ui-field__label" for="le-seats-computed">{{ __('admin.landing.seats_remaining') }}</label>
            <input type="number" class="ui-input u-num" id="le-seats-computed" readonly
                   aria-describedby="le-seats-computed-hint" x-bind:value="seatsComputed">
            <p class="ui-field__hint" id="le-seats-computed-hint">{{ __('admin.landing.seats_computed_hint') }}</p>
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="le-seats-override">{{ __('admin.landing.seats_override') }}</label>
            <input type="number" class="ui-input u-num" id="le-seats-override" min="0" max="10000" step="1" inputmode="numeric"
                   aria-describedby="le-seats-override-hint"
                   x-bind:value="setting('seats_override') ?? ''"
                   x-on:input="setSetting('seats_override', $event.target.value === '' ? null : Number($event.target.value))">
            <p class="ui-field__hint" id="le-seats-override-hint">{{ __('admin.landing.seats_override_hint') }}</p>
        </div>
    </div>
</div>
