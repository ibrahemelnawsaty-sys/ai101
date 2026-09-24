{{--
    One text of the landing page inside the content editor (D-114).

    Rendered inside <template x-for="field in …">: `field` is one entry of the
    catalogue built by App\Presenters\Admin\LandingEditor. One root element, as
    Alpine's x-for requires.

    Three rules, taken from the Sajaya editor:
      * the field starts with the words on the page NOW and is edited in place —
        never an empty box with the real text hidden behind a placeholder;
      * the original is shown only once the field differs from it;
      * an error (a live value such as :count removed, a counted form broken, a
        text too long) is shown at the field and blocks the publish — before a
        visitor ever sees it. The server refuses the same (PublishLandingRequest).

    A counted text ("{1} … |[2,*] :count …") is edited one form per box; the
    markers are the original's and cannot be typed away.

    @see BR-31 · PRD §9.18 · CONSTITUTION Art. 15, 16, 18 · D-114
--}}
<div class="le-field" x-show="matches(field)"
     x-bind:class="{ 'le-field--draft': fieldState(field.key) === 'draft', 'le-field--error': fieldHasIssue(field.key) }">
    <div class="le-field__hd">
        <span class="le-field__role" x-show="field.role" x-text="field.role"></span>
        <code class="le-field__key" dir="ltr" x-text="field.name"></code>
        <span class="le-state le-state--draft" x-show="fieldState(field.key) === 'draft'">{{ __('admin.landing_editor.state_draft') }}</span>
        <span class="le-state le-state--published" x-show="fieldState(field.key) === 'published'">{{ __('admin.landing_editor.state_published') }}</span>
        <button type="button" class="le__linkbtn le-field__revert" x-show="fieldState(field.key) !== 'original'" x-on:click="revert(field.key)">
            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-undo"/></svg>
            <span>{{ __('admin.landing_editor.revert') }}</span>
        </button>
    </div>

    <div class="le-field__langs" x-bind:class="{ 'le-field__langs--both': mode === 'both' }">
        <template x-for="lang in langs()" :key="lang">
            <div class="le-field__lang">
                <span class="le-field__lang-name" x-bind:id="fieldId(field, lang) + '-l'" x-text="langName(lang)"></span>

                <template x-if="! field.forms[lang]">
                    <div>
                        <template x-if="field.multiline">
                            <textarea class="ui-input ui-input--textarea le-field__input" rows="3"
                                      x-bind:id="fieldId(field, lang)"
                                      x-bind:dir="lang === 'en' ? 'ltr' : 'rtl'"
                                      x-bind:lang="lang"
                                      x-bind:aria-labelledby="fieldId(field, lang) + '-l'"
                                      x-bind:aria-invalid="(issueText(field.key, lang) !== '').toString()"
                                      x-bind:value="value(field.key, lang)"
                                      x-on:input="edit(field.key, lang, $event.target.value)"></textarea>
                        </template>
                        <template x-if="! field.multiline">
                            <input type="text" class="ui-input le-field__input"
                                   x-bind:id="fieldId(field, lang)"
                                   x-bind:dir="lang === 'en' ? 'ltr' : 'rtl'"
                                   x-bind:lang="lang"
                                   x-bind:aria-labelledby="fieldId(field, lang) + '-l'"
                                   x-bind:aria-invalid="(issueText(field.key, lang) !== '').toString()"
                                   x-bind:value="value(field.key, lang)"
                                   x-on:input="edit(field.key, lang, $event.target.value)">
                        </template>
                    </div>
                </template>

                <template x-if="field.forms[lang]">
                    <div class="le-forms">
                        <template x-for="(form, index) in field.forms[lang]" :key="index">
                            <label class="le-form">
                                <span class="le-form__label" x-text="form.label"></span>
                                <input type="text" class="ui-input le-field__input"
                                       x-bind:dir="lang === 'en' ? 'ltr' : 'rtl'"
                                       x-bind:lang="lang"
                                       x-bind:aria-invalid="(issueText(field.key, lang) !== '').toString()"
                                       x-bind:value="formValue(field, lang, index)"
                                       x-on:input="editForm(field, lang, index, $event.target.value)">
                            </label>
                        </template>
                    </div>
                </template>

                <p class="ui-field__hint ui-field__hint--error" role="alert" x-show="issueText(field.key, lang) !== ''">
                    <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#i-warn"/></svg>
                    <span x-text="issueText(field.key, lang)"></span>
                </p>

                <p class="le-field__orig" x-show="showOriginal(field, lang)" x-bind:dir="lang === 'en' ? 'ltr' : 'rtl'">
                    <b>{{ __('admin.landing_editor.original') }}:</b>
                    <span x-bind:lang="lang" x-text="field[lang]"></span>
                </p>
            </div>
        </template>
    </div>
</div>
