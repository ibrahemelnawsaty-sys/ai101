{{--
    FileUploader

    Drag and drop, multi-select, a per-file row with preview, size and a remove
    button that works BEFORE anything is sent.

    Removing a file rewrites the real <input type="file"> through a DataTransfer
    bag, so an ordinary multipart form submit carries exactly the files still on
    screen. There is no hidden second list and no bespoke XHR.

    Everything shown here is convenience only. The server re-checks every file:
    MIME sniffed from the CONTENT rather than the extension, the size limit
    re-applied, a random storage name, and storage OUTSIDE the web root
    (Article 24). A file this component accepted may still be refused, and that
    refusal is the truth.

    @see PRD §5.8, §5.9, §11.1, §12.5 · CONSTITUTION Articles 5, 15, 17, 18, 24

    Props
      variant     default | compact
      size        sm | md | lg      reserved
      state       default | loading | disabled | error
      name        form field name (posted as name[] when multiple)
      accept      the accept attribute — mirrors the server's allow-list
      multiple    allow more than one file
      maxFiles    client-side cap; the server enforces its own
      maxMb       per-file size cap in megabytes; the server enforces its own
      typesLabel  human-readable list of accepted formats for the hint
--}}


<div {{ $attributes->only('class')->class(['ui-uploader', 'ui-uploader--disabled' => $isDisabled]) }}>
    @if ($label !== null)
        <label class="ui-field__label" for="{{ $fieldId }}">{{ $label }}</label>
    @endif

    @if ($state === 'loading')
        <div class="ui-sk ui-sk-thumb" role="status" aria-live="polite">
            <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
        </div>
    @else
        <div
            x-data="uiUploader({
                multiple: @js((bool) $multiple),
                maxFiles: @js($maxFiles !== null ? (int) $maxFiles : 0),
                maxBytes: @js($maxBytes),
                accept: @js($accept ?? ''),
                messages: { tooLarge: @js(__('ui.uploader.too_large')) }
            })"
        >
            <div
                class="ui-uploader__drop"
                x-bind:class="{ 'is-dragging': dragging }"
                x-on:dragover.prevent="dragging = true"
                x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="onDrop($event)"
            >
                <input
                    type="file"
                    class="ui-uploader__input"
                    id="{{ $fieldId }}"
                    name="{{ $multiple ? $name . '[]' : $name }}"
                    x-ref="input"
                    x-on:change="onPick($event)"
                    @if ($accept) accept="{{ $accept }}" @endif
                    @if ($multiple) multiple @endif
                    @disabled($isDisabled)
                    @if ($message !== null) aria-invalid="true" @endif
                    aria-describedby="{{ $describedBy }}"
                >

                <span class="ui-uploader__art" aria-hidden="true">
                    <svg class="ui-icon ui-icon--lg" focusable="false"><use href="#i-up"/></svg>
                </span>

                <label class="ui-uploader__title" for="{{ $fieldId }}">{{ $title }}</label>

                @if ($hintText !== null)
                    <span class="ui-uploader__hint" id="{{ $hintId }}">{{ $hintText }}</span>
                @endif
            </div>

            {{-- The server is the judge: say so, once, where the user can see it. --}}
            <p class="ui-field__hint" id="{{ $fieldId }}-note">{{ __('ui.uploader.server_note') }}</p>

            <ul
                class="ui-uploader__list"
                x-show="hasFiles"
                x-cloak
                role="list"
                aria-label="{{ __('ui.uploader.selected_files') }}"
            >
                <template x-for="entry in files" x-bind:key="entry.id">
                    <li class="ui-uploader__file" x-bind:class="{ 'ui-uploader__file--error': entry.error }">
                        <div class="ui-uploader__file-head">
                            <template x-if="entry.preview">
                                <img class="ui-uploader__thumb" x-bind:src="entry.preview" alt="{{ __('ui.uploader.preview') }}">
                            </template>
                            <template x-if="! entry.preview">
                                <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#i-file"/></svg>
                            </template>

                            <span class="ui-uploader__file-name" x-text="entry.name"></span>
                            <span class="ui-uploader__file-size ui-num" x-text="entry.size"></span>

                            <button
                                type="button"
                                class="ui-iconbtn"
                                x-on:click="remove(entry.id)"
                                aria-label="{{ __('ui.uploader.remove') }}"
                            >
                                <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-trash"/></svg>
                            </button>
                        </div>

                        <p class="ui-uploader__file-error" x-show="entry.error" x-text="entry.error" role="alert"></p>
                    </li>
                </template>
            </ul>
        </div>
    @endif

    @if ($message !== null)
        <p class="ui-field__hint ui-field__hint--error" id="{{ $errorId }}" role="alert">
            <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#i-warn"/></svg>
            <span>{{ $message }}</span>
        </p>
    @endif
</div>
