{{--
    The hero copy the centre writes for the featured cohort (D-114): the big
    headline and the line beneath it. An empty headline shows the programme's
    name, which is what the page printed before this field was wired to it.

    @see BR-31 · PRD §9.1.1 · D-114
--}}
<div class="le-settings">
    <div class="ui-field">
        <label class="ui-field__label" for="le-hero-title">{{ __('admin.landing.hero_title') }}</label>
        <input type="text" class="ui-input" id="le-hero-title" maxlength="300"
               aria-describedby="le-hero-title-hint"
               x-bind:placeholder="programName"
               x-bind:value="setting('hero_title')"
               x-on:input="setSetting('hero_title', $event.target.value)">
        <p class="ui-field__hint" id="le-hero-title-hint">{{ __('admin.landing_editor.hero_title_hint') }}</p>
    </div>
    <div class="ui-field">
        <label class="ui-field__label" for="le-hero-subtitle">{{ __('admin.landing.hero_subtitle') }}</label>
        <textarea class="ui-input ui-input--textarea" id="le-hero-subtitle" rows="3" maxlength="2000"
                  x-bind:value="setting('hero_subtitle')"
                  x-on:input="setSetting('hero_subtitle', $event.target.value)"></textarea>
    </div>
</div>
