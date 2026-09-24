{{--
    The question list inside the content editor (D-114): add, edit, reorder and
    remove, all in the same draft as the texts and published with them.

    An entry's key is only ever proposed by the browser: the server keeps a key
    it already stored and generates every other one, so no entry can be aimed at
    another's (Admin\LandingController::publishSettings).

    @see BR-31 · PRD §9.1.1, §9.18 · D-114
--}}
<div class="le-faq">
    <template x-if="faqItems().length === 0">
        <x-ui.empty-state icon="chat" size="sm"
            :title="__('admin.landing.faq_empty_title')"
            :description="__('admin.landing.faq_empty_body')" />
    </template>

    <ol class="le-faq__list" x-show="faqItems().length > 0">
        <template x-for="(item, index) in faqItems()" :key="item.uid">
            <li class="le-faq__item" x-bind:class="{ 'le-faq__item--error': faqIssue(item) }">
                <div class="le-faq__hd">
                    <span class="le-faq__n" x-text="faqNumber(index)"></span>
                    <span class="le-faq__acts">
                        <button type="button" class="ui-iconbtn" x-on:click="moveFaq(index, -1)" x-bind:disabled="index === 0"
                                aria-label="{{ __('admin.landing_editor.faq_move_up') }}" title="{{ __('admin.landing_editor.faq_move_up') }}">
                            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-chevup"/></svg>
                        </button>
                        <button type="button" class="ui-iconbtn" x-on:click="moveFaq(index, 1)" x-bind:disabled="index === faqItems().length - 1"
                                aria-label="{{ __('admin.landing_editor.faq_move_down') }}" title="{{ __('admin.landing_editor.faq_move_down') }}">
                            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-chevdown"/></svg>
                        </button>
                        <button type="button" class="ui-iconbtn le-faq__remove" x-on:click="removeFaq(index)"
                                aria-label="{{ __('admin.landing_editor.faq_remove') }}" title="{{ __('admin.landing_editor.faq_remove') }}">
                            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-trash"/></svg>
                        </button>
                    </span>
                </div>
                <div class="ui-field">
                    <label class="ui-field__label" x-bind:for="'le-faq-q-' + item.uid">{{ __('admin.landing.faq_question') }}</label>
                    <input type="text" class="ui-input" maxlength="300"
                           x-bind:id="'le-faq-q-' + item.uid"
                           x-bind:value="item.question"
                           x-bind:aria-invalid="(faqIssue(item) && item.question.trim() === '').toString()"
                           x-on:input="setFaq(index, 'question', $event.target.value)">
                </div>
                <div class="ui-field">
                    <label class="ui-field__label" x-bind:for="'le-faq-a-' + item.uid">{{ __('admin.landing.faq_answer') }}</label>
                    <textarea class="ui-input ui-input--textarea" rows="3" maxlength="3000"
                              x-bind:id="'le-faq-a-' + item.uid"
                              x-bind:value="item.answer"
                              x-bind:aria-invalid="(faqIssue(item) && item.answer.trim() === '').toString()"
                              x-on:input="setFaq(index, 'answer', $event.target.value)"></textarea>
                </div>
            </li>
        </template>
    </ol>

    <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-on:click="addFaq()">
        <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-plus"/></svg>
        <span>{{ __('admin.landing.faq_add') }}</span>
    </button>
</div>
