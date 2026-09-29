{{--
    Admin · one e-mail template (BR-31, D-136).

    The SUBJECT and the BODY of a letter, over the file's defaults — and a live
    picture of the letter beside them, on a phone or on a desktop. What is fixed
    is on the page as plainly as what is editable: the heading, the button, the
    security sentences and the links never change, and the person editing reads
    them here rather than wondering.

    The rules are the server's. The browser asks the preview endpoint for the same
    sentences a save would refuse with and shows them while the person types; it
    decides nothing (art. 5). The picture is rendered by the real letter shell from
    a draft that is never stored, with sample values, and it sends nothing (D-02).

    Four states: error · loading (the picture waits under a skeleton) · empty (no
    editable field) · normal.

    @see BR-31, BR-33 · PRD §9.16, §9.18 · CONSTITUTION art. 5, art. 15, art. 17 · D-02, D-114, D-136
--}}
@extends('layouts.app')

@section('title', __('admin.email_editor.title'))
@section('subtitle', $editor->label)
@section('entry', 'resources/js/admin-email-template.js')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.settings.template', $editor->key)" />
    @elseif ($editor->fields === [])
        <x-ui.empty-state icon="mail"
            :title="__('admin.email_editor.no_editable')"
            :action-label="__('admin.email_editor.back')" :action-href="route('admin.settings.edit')" />
    @else
        <p class="u-mb-4">
            <x-ui.button variant="ghost" size="sm" icon="chev-end" :href="route('admin.settings.edit')">{{ __('admin.email_editor.back') }}</x-ui.button>
        </p>

        <div class="emed"
            x-data="emailEditor({
                previewUrl: @js(route('admin.settings.template.preview', $editor->key), JSON_UNESCAPED_SLASHES),
                drafts: @js(collect($editor->fields)->mapWithKeys(fn ($field) => [$field['name'] => old($field['name'], $field['value'])])->all(), JSON_UNESCAPED_UNICODE),
                failed: @js(__('admin.email_editor.preview_error'), JSON_UNESCAPED_UNICODE)
            })">

            {{-- The words ----------------------------------------------------- --}}
            <div class="emed__form">
                <x-ui.card icon="mail" :title="$editor->label">
                    @if ($editor->isCustomised)
                        <x-slot:action><x-ui.pill variant="primary" icon="pencil">{{ __('admin.email_editor.customised') }}</x-ui.pill></x-slot:action>
                    @endif

                    <p class="footnote">{{ __('admin.email_editor.intro') }}</p>

                    <form method="POST" action="{{ route('admin.settings.template.update', $editor->key) }}" id="email-template-form">
                        @csrf
                        @method('PUT')

                        @foreach ($editor->fields as $field)
                            @if ($field['name'] === 'body')
                                <x-ui.textarea name="body" :rows="$field['rows']" :label="$field['label']"
                                    :value="old('body', $field['value'])" x-model="drafts.body" />
                            @else
                                <x-ui.input name="{{ $field['name'] }}" :label="$field['label']"
                                    :value="old($field['name'], $field['value'])" x-model="drafts.{{ $field['name'] }}" />
                            @endif

                            {{-- The same sentence a save would refuse with, told while typing.
                                 The live region is the wrapper, always in the page: an element that
                                 is only shown by x-show is not announced by a screen reader. --}}
                            <div role="status" aria-live="polite">
                                <p class="hint hint--bad" x-show="messages.{{ $field['name'] }}" x-cloak>
                                    <x-ui.icon name="warn" /><span x-text="messages.{{ $field['name'] }}"></span>
                                </p>
                            </div>

                            {{-- The values this field carries: the only ones it may use, and all
                                 of them stay. Said under the field, not once for the whole letter —
                                 each field of a letter is handed its own values. --}}
                            <div class="emed__vars">
                                <b>{{ __('admin.email_editor.field_variables') }}</b>
                                @if ($field['variables'] === [])
                                    <span>{{ __('admin.email_editor.field_variables_none') }}</span>
                                @else
                                    <ul class="filelist filelist--inline">
                                        @foreach ($field['variables'] as $variable)
                                            <li><code dir="ltr">{{ $variable['name'] }}</code> <small>{{ __('admin.email_editor.sample_of', ['sample' => $variable['sample']]) }}</small></li>
                                        @endforeach
                                    </ul>
                                    <small>{{ __('admin.email_editor.field_variables_hint') }}</small>
                                @endif
                            </div>

                            <details class="emed__orig">
                                <summary>{{ __('admin.email_editor.original') }}</summary>
                                <p dir="auto">{{ \App\Presenters\Support\Present::isolateTokens($field['original']) }}</p>
                                <p class="footnote">{{ __('admin.email_editor.original_hint') }}</p>
                            </details>
                        @endforeach

                    </form>

                    {{-- The two buttons sit OUTSIDE the save form: the reset dialog is a
                         form of its own, and a form inside a form is dropped by every
                         browser — the reset button then submitted the SAVE form and
                         saved instead of restoring. The save button reaches its form by
                         the `form` attribute. --}}
                    <div class="row__acts">
                        <x-ui.button icon="check" variant="primary" type="submit" form="email-template-form"
                            x-bind:disabled="hasProblem">{{ __('admin.email_editor.save') }}</x-ui.button>
                        @if ($editor->isCustomised)
                            <x-ui.confirm name="email-template-reset" :action="route('admin.settings.template.reset', $editor->key)" method="DELETE"
                                :title="__('admin.email_editor.reset_title')"
                                :description="__('admin.email_editor.reset_body')"
                                :confirm-label="__('admin.email_editor.reset_confirm')"
                                :trigger-label="__('admin.email_editor.reset')" trigger-icon="undo" trigger-variant="secondary" />
                        @endif
                    </div>
                </x-ui.card>

                @if ($editor->fixed !== [])
                    <x-ui.card class="u-mt-4" icon="lock" :title="__('admin.email_editor.fixed_title')">
                        <p class="footnote">{{ __('admin.email_editor.fixed_hint') }}</p>
                        <dl class="deflist">
                            @foreach ($editor->fixed as $part)
                                <div>
                                    <dt>{{ $part['label'] }}</dt>
                                    <dd>{{ \App\Presenters\Support\Present::isolateTokens($part['text']) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </x-ui.card>
                @endif
            </div>

            {{-- The picture --------------------------------------------------- --}}
            <div class="emed__view">
                <x-ui.card icon="eye" :title="__('admin.email_editor.preview_title')">
                    <p class="footnote">{{ __('admin.email_editor.preview_hint') }}</p>

                    <div class="emed__bar">
                        <div class="segmented" role="group" aria-label="{{ __('admin.email_editor.preview_title') }}">
                            <button type="button" class="segmented__b" x-bind:aria-pressed="frame === 'mobile'"
                                x-bind:class="frame === 'mobile' ? 'is-on' : ''"
                                x-on:click="frame = 'mobile'">{{ __('admin.email_editor.preview_mobile') }}</button>
                            <button type="button" class="segmented__b" x-bind:aria-pressed="frame === 'desktop'"
                                x-bind:class="frame === 'desktop' ? 'is-on' : ''"
                                x-on:click="frame = 'desktop'">{{ __('admin.email_editor.preview_desktop') }}</button>
                        </div>
                    </div>

                    <p class="emed__subject" x-show="subject" x-cloak>
                        <b>{{ __('admin.email_editor.preview_subject') }}</b> <span dir="auto" x-text="subject"></span>
                    </p>

                    <div role="alert">
                        <p class="hint hint--bad" x-show="state === 'error'" x-cloak>
                            <x-ui.icon name="warn" /><span x-text="failure"></span>
                            <x-ui.button icon="refresh" variant="ghost" size="sm" type="button"
                                x-on:click="render()">{{ __('app.retry') }}</x-ui.button>
                        </p>
                    </div>

                    <div class="emed__stage" x-ref="stage">
                        <div class="ui-sk emed__wait" x-show="state === 'loading' && html === ''" role="status" aria-live="polite">
                            <span class="ui-sr">{{ __('admin.email_editor.preview_loading') }}</span>
                        </div>
                        {{-- The frame is laid out at the width of a phone or of a desktop client and
                             scaled to fit the column (email-editor.js). sandbox="": the picture is a
                             document, never a page — no scripts, no forms, no navigation, however the
                             words were typed. --}}
                        {{-- x-show and x-bind:style never share an element: the one rewrites the
                             other's `display` (D-64) — so the show sits on a wrapper. --}}
                        <div x-show="html !== ''" x-cloak>
                            <div class="emed__box" x-bind:style="boxStyle">
                                <iframe class="emed__frame" sandbox="" x-bind:srcdoc="html" x-bind:style="frameStyle"
                                    title="{{ __('admin.email_editor.preview_frame') }}"></iframe>
                            </div>
                        </div>
                    </div>
                </x-ui.card>
            </div>
        </div>
    @endif
@endsection
