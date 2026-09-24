{{--
    The about text the centre writes for the featured cohort (D-114). Empty
    shows the programme's description; a blank line starts a new paragraph.

    @see BR-31 · PRD §9.1.1 · D-114
--}}
<div class="le-settings">
    <div class="ui-field">
        <label class="ui-field__label" for="le-about-body">{{ __('admin.landing.about_body') }}</label>
        <textarea class="ui-input ui-input--textarea" id="le-about-body" rows="6" maxlength="5000"
                  aria-describedby="le-about-body-hint"
                  x-bind:value="setting('about_body')"
                  x-on:input="setSetting('about_body', $event.target.value)"></textarea>
        <p class="ui-field__hint" id="le-about-body-hint">{{ __('admin.landing_editor.about_body_hint') }}</p>
    </div>
</div>
