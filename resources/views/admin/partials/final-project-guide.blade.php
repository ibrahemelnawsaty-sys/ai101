{{--
    Admin · the final project's guide card (D-127).

    One row per language: its state, the page on show, a preview in a new tab,
    the editor, and the availability switch. Publishing is not here — it is the
    cohort's primary coordinator's press. Under the rows, a copy from another
    cohort; on ?guide=ar|en, the editor with the page and its paged history.

    Every label, flag and address comes from FinalProjectGuidePanel; every
    write answers to its own FormRequest and to FinalProjectPolicy::update.

    @see D-127 · CONSTITUTION Art. 5, Art. 17, Art. 18
--}}
<x-ui.card class="dc--span u-mt-4" icon="file" id="guide" :title="__('admin.final_project.guide.title')">
    <p class="form__note">{{ __('admin.final_project.guide.intro') }}</p>

    <ul class="reslist u-mt-4" role="list">
        @foreach ($guidePanel->languages as $language)
            <li class="row">
                <div class="row__m">
                    <b>{{ __('admin.final_project.guide.language_heading', ['language' => $language['label']]) }}</b>
                    <span class="u-inline">
                        <x-ui.pill :variant="$language['stateVariant'] === 'muted' ? 'neutral' : $language['stateVariant']">{{ $language['stateLabel'] }}</x-ui.pill>
                    </span>
                    <span class="note__body u-num">{{ $language['versionLabel'] }}</span>
                    @if ($language['locale'] === 'en')
                        <span class="note__body">{{ __('admin.final_project.guide.english_hint') }}</span>
                    @endif
                    @unless ($language['hasContent'])
                        <span class="note__body">{{ __('admin.final_project.guide.needs_content_hint') }}</span>
                    @endunless
                </div>

                <div class="row__e row__acts">
                    @if ($language['previewUrl'])
                        <x-ui.button variant="ghost" size="sm" icon="external" :href="$language['previewUrl']"
                            target="_blank" rel="noopener" aria-label="{{ __('admin.final_project.guide.preview_label') }}">{{ __('admin.final_project.guide.preview') }}</x-ui.button>
                    @endif
                    <x-ui.button variant="secondary" size="sm" icon="file" :href="$language['editUrl']">{{ __('admin.final_project.guide.edit') }}</x-ui.button>

                    <form method="POST" action="{{ $language['availabilityAction'] }}">
                        @csrf
                        @method('PUT')
                        @if ($language['isAvailable'])
                            <input type="hidden" name="available" value="0">
                            <x-ui.button variant="secondary" size="sm" type="submit" icon="lock"
                                title="{{ __('admin.final_project.guide.withdraw_hint') }}">{{ __('admin.final_project.guide.withdraw') }}</x-ui.button>
                        @else
                            <input type="hidden" name="available" value="1">
                            <x-ui.button variant="primary" size="sm" type="submit" icon="check"
                                :state="$language['hasContent'] ? 'default' : 'disabled'">{{ __('admin.final_project.guide.make_available') }}</x-ui.button>
                        @endif
                    </form>
                </div>
            </li>
        @endforeach
    </ul>

    @error('available')
        <p class="ui-field__error" role="alert">{{ $message }}</p>
    @enderror

    {{-- Copy another cohort's guide ------------------------------------------------ --}}
    <h3 class="abrief__sub">{{ __('admin.final_project.guide.copy_title') }}</h3>
    @if (count($guidePanel->copyOptions) === 0)
        <p class="form__note">{{ __('admin.final_project.guide.copy_empty') }}</p>
    @else
        <p class="form__note">{{ __('admin.final_project.guide.copy_hint') }}</p>
        <form method="POST" action="{{ $guidePanel->copyAction }}" class="toolbar__filters">
            @csrf
            <x-ui.select name="source_cohort_id" required :label="__('admin.final_project.guide.copy_source')"
                :options="$guidePanel->copyOptions" />
            <x-ui.button variant="secondary" size="sm" type="submit" icon="refresh">{{ __('admin.final_project.guide.copy_action') }}</x-ui.button>
        </form>
    @endif
</x-ui.card>

{{-- The editor (?guide=ar|en) ------------------------------------------------------ --}}
@if ($guidePanel->editor)
    <x-ui.card class="dc--span u-mt-4" icon="file" id="guide-editor" :title="$guidePanel->editor['title']">
        <p class="form__note">{{ __('admin.final_project.guide.editor_intro') }}</p>

        <form method="POST" action="{{ $guidePanel->editor['saveAction'] }}" enctype="multipart/form-data">
            @csrf

            <x-ui.textarea name="html" rows="18" ltr
                :label="__('admin.final_project.guide.fields.html')"
                :hint="__('admin.final_project.guide.fields.html_hint')"
                :value="old('html', $guidePanel->editor['html'])" />

            <x-ui.file-uploader name="file" variant="compact" accept=".html,.htm,text/html" :max-mb="2" types-label="HTML"
                :label="__('admin.final_project.guide.fields.file')" />
            <p class="hint"><x-ui.icon name="info" /><span>{{ __('admin.final_project.guide.fields.file_hint') }}</span></p>
            @error('file')
                <p class="ui-field__error" role="alert">{{ $message }}</p>
            @enderror

            <div class="row__acts u-mt-4">
                <x-ui.button variant="primary" type="submit">{{ __('admin.final_project.guide.save') }}</x-ui.button>
                <x-ui.button variant="ghost" :href="$guidePanel->editor['closeUrl']">{{ __('admin.final_project.guide.close') }}</x-ui.button>
            </div>
        </form>

        <h3 class="abrief__sub">{{ __('admin.final_project.guide.history_title') }}</h3>
        @error('version')
            <p class="ui-field__error" role="alert">{{ $message }}</p>
        @enderror

        @if (count($guidePanel->editor['versions']) === 0)
            <x-ui.empty-state icon="file" size="sm"
                :title="__('admin.final_project.guide.history_empty')"
                :description="__('admin.final_project.guide.editor_intro')" />
        @else
            <ol class="reslist u-mt-2" role="list">
                @foreach ($guidePanel->editor['versions'] as $version)
                    <li class="row">
                        <div class="row__m">
                            <b class="u-num">{{ $version['label'] }}</b>
                            <span class="note__body">{{ $version['sourceLabel'] }} · {{ $version['author'] }} · <span class="u-num">{{ $version['size'] }}</span></span>
                        </div>
                        <div class="row__e row__acts">
                            @if ($version['isCurrent'])
                                <x-ui.pill variant="success" icon="check">{{ __('admin.final_project.guide.current') }}</x-ui.pill>
                            @else
                                <form method="POST" action="{{ $guidePanel->editor['restoreAction'] }}">
                                    @csrf
                                    <input type="hidden" name="version" value="{{ $version['number'] }}">
                                    <x-ui.button variant="secondary" size="sm" type="submit" icon="undo">{{ __('admin.final_project.guide.restore') }}</x-ui.button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>

            @if ($guidePanel->editor['history'])
                <x-ui.pagination :paginator="$guidePanel->editor['history']" />
            @endif
        @endif
    </x-ui.card>
@endif
