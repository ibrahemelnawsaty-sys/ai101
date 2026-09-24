{{--
    Admin · the "Landing page content" tab (BR-31 · D-114).

    Rebuilt on the approved Sajaya editor:
      * a toolbar: search across every text, Arabic / English / both, show or
        hide the preview, reset the whole page;
      * a tab per section of the page, each with its own reset and the count of
        unpublished edits in it;
      * a card per block of text; every text shows its current words in the
        chosen language(s), its state (original / published / draft), its
        original once it differs, and a "back to original";
      * the REAL landing page beside the fields, in a desktop or a phone frame,
        re-rendered by the server with the draft a moment after each edit;
      * a sticky publish bar: the draft lives in this browser until "publish"
        sends it in one request (Ctrl/Cmd+S publishes too).

    Nothing is decided here. The script (resources/js/landing-editor.js) only
    mirrors the rules PublishLandingRequest enforces on the server, and every
    sentence it prints comes from lang/*/admin.php through the payload below.

    Four states: error · loading skeleton shaped like the editor (shown until
    the script takes over) · empty (per block: no cohort, no questions, no
    search result) · normal.

    @see PRD §9.1, §9.18 · BR-31, BR-33, BR-36 · CONSTITUTION Art. 5, 15, 16, 17, 18
--}}
@extends('layouts.app')

@section('title', __('admin.landing.title'))
@section('subtitle', $contextLabel ?? '')
@section('entry', 'resources/js/admin-landing.js')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.landing.edit')" />
    @else
        <div class="le" x-data="landingEditor" data-payload="#landing-editor-data">
            <script type="application/json" id="landing-editor-data">@json($editor->payload)</script>

            {{-- Loading: shaped like the editor, replaced the moment the script runs. --}}
            <div class="le__skeleton" x-show="! ready" role="status" aria-live="polite">
                <span class="ui-sr">{{ __('admin.landing_editor.loading_label') }}</span>
                <x-ui.skeleton height="var(--d-1)" width="100%" />
                <div class="le__skeleton-tabs">
                    <x-ui.skeleton shape="chip" :count="8" />
                </div>
                <div class="le__skeleton-grid">
                    <x-ui.skeleton shape="card" :count="2" />
                </div>
                <noscript><p class="le__noscript">{{ __('admin.landing_editor.noscript') }}</p></noscript>
            </div>

            <div class="le__app" x-cloak x-show="ready">

                {{-- Toolbar ------------------------------------------------------ --}}
                <section class="le__bar le-card">
                    <p class="le__lead">{{ __('admin.landing_editor.lead') }}</p>

                    <div class="le__tools">
                        <label class="le__search">
                            <span class="ui-sr">{{ __('admin.landing_editor.search_label') }}</span>
                            <svg class="ui-icon ui-icon--sm le__search-icon" aria-hidden="true" focusable="false"><use href="#i-search"/></svg>
                            <input type="search" class="ui-input le__search-input"
                                   x-model.debounce.150ms="search"
                                   placeholder="{{ __('admin.landing_editor.search_placeholder') }}">
                        </label>

                        <div class="le__seg" role="group" aria-label="{{ __('admin.landing_editor.lang_aria') }}">
                            <button type="button" class="le__seg-btn" x-bind:aria-pressed="(mode === 'ar').toString()" x-on:click="setMode('ar')">{{ __('admin.landing_editor.arabic') }}</button>
                            <button type="button" class="le__seg-btn" lang="en" x-bind:aria-pressed="(mode === 'en').toString()" x-on:click="setMode('en')">{{ __('admin.landing_editor.english') }}</button>
                            <button type="button" class="le__seg-btn" x-bind:aria-pressed="(mode === 'both').toString()" x-on:click="setMode('both')">{{ __('admin.landing_editor.both') }}</button>
                        </div>

                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-on:click="showPreview = ! showPreview" x-bind:aria-pressed="showPreview.toString()">
                            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false" x-show="showPreview"><use href="#i-eye-off"/></svg>
                            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false" x-show="! showPreview"><use href="#i-eye"/></svg>
                            <span x-show="showPreview">{{ __('admin.landing_editor.preview_hide') }}</span>
                            <span x-show="! showPreview">{{ __('admin.landing_editor.preview_show') }}</span>
                        </button>

                        <button type="button" class="ui-btn ui-btn--danger ui-btn--sm le__reset-all" x-on:click="askResetAll()" x-bind:disabled="busy">
                            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-undo"/></svg>
                            <span>{{ __('admin.landing_editor.reset_all') }}</span>
                        </button>
                    </div>

                    <p class="le__note" x-show="mode !== 'ar'">
                        <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-info"/></svg>
                        <span>{{ __('admin.landing_editor.english_note') }}</span>
                    </p>
                </section>

                {{-- Section tabs --------------------------------------------------- --}}
                <div class="le__tabs" role="tablist" aria-label="{{ __('admin.landing_editor.sections_aria') }}"
                     x-bind:class="{ 'is-muted': query !== '' }"
                     x-on:keydown="onTabsKey($event)">
                    <template x-for="section in sections" :key="section.key">
                        <button type="button" role="tab" class="le__tab"
                                x-bind:id="'le-tab-' + section.key"
                                x-bind:aria-selected="(query === '' && section.key === active).toString()"
                                x-bind:aria-controls="'le-panel-' + section.key"
                                x-bind:tabindex="section.key === active ? 0 : -1"
                                x-on:click="openSection(section.key)">
                            <span x-text="section.label"></span>
                            <span class="le__tab-count u-num" x-show="sectionDrafts(section.key) > 0" x-text="sectionDrafts(section.key)"></span>
                            <span class="le__tab-dot" x-show="sectionDrafts(section.key) === 0 && sectionPublished(section.key) > 0" aria-hidden="true"></span>
                        </button>
                    </template>
                </div>

                <div class="le__grid" x-bind:class="{ 'le__grid--split': showPreview && query === '' }">

                    {{-- Fields ------------------------------------------------------ --}}
                    <div class="le__fields">

                        {{-- Search results across every section --}}
                        <template x-if="query !== ''">
                            <div class="le__results">
                                <p class="le__results-note" aria-live="polite" x-text="searchNote()"></p>
                                <template x-if="results().length === 0">
                                    <div class="le-card">
                                        <x-ui.empty-state icon="search"
                                            :title="__('admin.landing_editor.no_results_title')"
                                            :description="__('admin.landing_editor.no_results_body')" />
                                        <div class="le__center">
                                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-on:click="search = ''">{{ __('admin.landing_editor.clear_search') }}</button>
                                        </div>
                                    </div>
                                </template>
                                <template x-for="hit in results()" :key="hit.section.key">
                                    <section class="le__group le-card">
                                        <div class="le__group-hd le__group-hd--static">
                                            <h3 class="le__group-title" x-text="hit.section.label"></h3>
                                            <span class="le__pill le__pill--end" x-text="fieldsLabel(hit.fields.length)"></span>
                                        </div>
                                        <template x-for="field in hit.fields" :key="field.key">
                                            @include('admin.partials.landing-field')
                                        </template>
                                    </section>
                                </template>
                            </div>
                        </template>

                        {{-- The open section --}}
                        <template x-if="query === '' && current()">
                            <div class="le__panel" role="tabpanel"
                                 x-bind:id="'le-panel-' + current().key"
                                 x-bind:aria-labelledby="'le-tab-' + current().key">

                                <div class="le__panel-hd">
                                    <div>
                                        <h2 class="le__panel-title" x-text="current().label"></h2>
                                        <p class="le__panel-hint" x-show="current().hint" x-text="current().hint"></p>
                                        {{-- D-117 — a note, not a link: programmes and cohorts are the
                                             general supervisor's screens. --}}
                                        <template x-if="current().source">
                                            <p class="le__source">
                                                <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-info"/></svg>
                                                <span x-text="current().source.label"></span>
                                            </p>
                                        </template>
                                    </div>
                                    <button type="button" class="le__linkbtn le__linkbtn--danger"
                                            x-on:click="askResetSection(current())"
                                            x-bind:disabled="busy || (sectionPublished(current().key) === 0 && sectionDrafts(current().key) === 0)">
                                        <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-undo"/></svg>
                                        <span>{{ __('admin.landing_editor.reset_section') }}</span>
                                    </button>
                                </div>

                                <template x-for="group in current().groups" :key="group.id">
                                    <section class="le__group le-card">
                                        <button type="button" class="le__group-hd" x-bind:aria-expanded="isOpen(group).toString()" x-on:click="toggleGroup(group)">
                                            <svg class="ui-icon ui-icon--sm le__chev" x-bind:class="{ 'is-closed': ! isOpen(group) }" aria-hidden="true" focusable="false"><use href="#i-chevdown"/></svg>
                                            <h3 class="le__group-title" x-text="group.label"></h3>
                                            <span class="le__pill le__pill--draft u-num" x-show="groupDrafts(group) > 0" x-text="groupDrafts(group)"></span>
                                            <span class="le__pill le__pill--end" x-show="group.fields.length > 0" x-text="group.fields.length"></span>
                                        </button>

                                        <div class="le__group-body" x-show="isOpen(group)">
                                            <p class="le__group-hint" x-show="group.hint" x-text="group.hint"></p>

                                            {{-- Settings blocks: values on landing_settings, not texts --}}
                                            <template x-if="group.setting && settings === null">
                                                <div class="le__nocohort">
                                                    <x-ui.empty-state icon="cal"
                                                        :title="__('admin.landing_editor.no_cohort_title')"
                                                        :description="__('admin.landing_editor.no_cohort_body')"
                                                        :action-label="__('admin.landing_editor.no_cohort_action')"
                                                        :action-href="route('home')" />
                                                </div>
                                            </template>
                                            <template x-if="group.setting === 'registration' && settings !== null">
                                                @include('admin.partials.landing-registration')
                                            </template>
                                            <template x-if="group.setting === 'hero_copy' && settings !== null">
                                                @include('admin.partials.landing-hero-copy')
                                            </template>
                                            <template x-if="group.setting === 'about_copy' && settings !== null">
                                                @include('admin.partials.landing-about-copy')
                                            </template>
                                            <template x-if="group.setting === 'faq_list' && settings !== null">
                                                @include('admin.partials.landing-faq')
                                            </template>

                                            <template x-for="field in group.fields" :key="field.key">
                                                @include('admin.partials.landing-field')
                                            </template>
                                        </div>
                                    </section>
                                </template>
                            </div>
                        </template>
                    </div>

                    {{-- Live preview ------------------------------------------------ --}}
                    <aside class="le__preview le-card" x-show="showPreview && query === ''" aria-label="{{ __('admin.landing_editor.preview.title') }}">
                        <div class="le__pv-bar">
                            <div class="le__seg" role="group" aria-label="{{ __('admin.landing_editor.preview.device_aria') }}">
                                <button type="button" class="le__seg-btn" x-bind:aria-pressed="(device === 'desktop').toString()" x-on:click="setDevice('desktop')">
                                    <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-monitor"/></svg>
                                    <span>{{ __('admin.landing_editor.preview.desktop') }}</span>
                                </button>
                                <button type="button" class="le__seg-btn" x-bind:aria-pressed="(device === 'mobile').toString()" x-on:click="setDevice('mobile')">
                                    <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-phone"/></svg>
                                    <span>{{ __('admin.landing_editor.preview.mobile') }}</span>
                                </button>
                            </div>
                            <span class="le__pv-acts">
                                <button type="button" class="ui-iconbtn" x-on:click="refreshPreview()"
                                        title="{{ __('admin.landing_editor.preview.refresh') }}"
                                        aria-label="{{ __('admin.landing_editor.preview.refresh') }}">
                                    <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-refresh"/></svg>
                                </button>
                                <a class="ui-iconbtn" href="{{ route('home') }}" target="_blank" rel="noopener"
                                   title="{{ __('admin.landing_editor.preview.open') }}"
                                   aria-label="{{ __('admin.landing_editor.preview.open') }}">
                                    <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-external"/></svg>
                                </a>
                            </span>
                        </div>

                        {{-- The stage is LTR so the scaled frame anchors to its left
                             edge, whatever the page direction; the page inside the
                             frame keeps its own direction. --}}
                        <div class="le__stage" dir="ltr" x-ref="stage" x-bind:class="'le__stage--' + device">
                            <div class="le__device" x-bind:style="stageStyle()">
                                <iframe name="le-frame-a" x-ref="frameA" class="le__frame"
                                        x-bind:class="{ 'is-active': activeFrame === 'a' }"
                                        x-bind:style="frameStyle()"
                                        x-on:load="frameLoaded('a')"
                                        sandbox="allow-same-origin allow-scripts"
                                        title="{{ __('admin.landing_editor.preview.title') }}"
                                        x-bind:tabindex="activeFrame === 'a' ? 0 : -1"
                                        x-bind:aria-hidden="(activeFrame !== 'a').toString()"></iframe>
                                <iframe name="le-frame-b" x-ref="frameB" class="le__frame"
                                        x-bind:class="{ 'is-active': activeFrame === 'b' }"
                                        x-bind:style="frameStyle()"
                                        x-on:load="frameLoaded('b')"
                                        sandbox="allow-same-origin allow-scripts"
                                        title="{{ __('admin.landing_editor.preview.title') }}"
                                        x-bind:tabindex="activeFrame === 'b' ? 0 : -1"
                                        x-bind:aria-hidden="(activeFrame !== 'b').toString()"></iframe>
                            </div>
                            <div class="le__pv-loading" x-show="! previewReady" role="status" aria-live="polite">
                                <span class="ui-spinner" aria-hidden="true"></span>
                                <span>{{ __('admin.landing_editor.preview.loading') }}</span>
                            </div>
                        </div>

                        <p class="le__pv-note">{{ __('admin.landing_editor.preview.note') }}</p>

                        {{-- The draft reaches the frame as an ordinary form post, so
                             the page that comes back is the real page with its own
                             scripts. Nothing here is stored (PreviewLandingRequest). --}}
                        <form x-ref="previewForm" method="POST" action="{{ route('admin.landing.preview') }}" class="le__hidden">
                            @csrf
                            <input type="hidden" name="state" x-ref="previewState">
                            <input type="hidden" name="lang" x-ref="previewLang">
                        </form>
                    </aside>
                </div>

                {{-- Publish bar ------------------------------------------------------ --}}
                <div class="le__publish" x-bind:class="{ 'is-dirty': dirtyCount() > 0 }">
                    <span class="le__publish-note" aria-live="polite" x-text="draftNote()"></span>
                    <span class="le__publish-err" x-show="invalidCount() > 0" role="alert">
                        <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-warn"/></svg>
                        <span x-text="blockedNote()"></span>
                    </span>
                    <span class="le__publish-acts">
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-show="dirtyCount() > 0" x-on:click="askDiscard()" x-bind:disabled="busy">
                            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-trash"/></svg>
                            <span>{{ __('admin.landing_editor.discard') }}</span>
                        </button>
                        <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" x-on:click="publish()"
                                x-bind:disabled="busy || dirtyCount() === 0 || invalidCount() > 0"
                                x-bind:aria-busy="busy.toString()">
                            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-send"/></svg>
                            <span x-text="publishLabel()">{{ __('admin.landing_editor.publish') }}</span>
                        </button>
                    </span>
                </div>

                {{-- Confirmation ------------------------------------------------------ --}}
                <div class="ui-modal ui-modal--sm ui-modal--danger" x-show="confirm.open" x-cloak
                     x-on:keydown.escape.window="closeConfirm()" x-on:keydown="trapConfirm($event)">
                    <div class="ui-modal__backdrop" x-on:click="closeConfirm()" aria-hidden="true"></div>
                    <div class="ui-modal__panel" role="alertdialog" aria-modal="true"
                         aria-labelledby="le-confirm-title" aria-describedby="le-confirm-body" tabindex="-1" x-ref="confirmPanel">
                        <div class="ui-modal__header">
                            <span class="ui-modal__icon" aria-hidden="true">
                                <svg class="ui-icon" focusable="false"><use href="#i-warn"/></svg>
                            </span>
                            <div class="ui-modal__heading">
                                <h2 class="ui-modal__title" id="le-confirm-title" x-text="confirm.title"></h2>
                                <p class="ui-modal__desc" id="le-confirm-body" x-text="confirm.body"></p>
                            </div>
                        </div>
                        <div class="ui-modal__footer">
                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--md" x-on:click="closeConfirm()" x-text="t('cancel')"></button>
                            <button type="button" class="ui-btn ui-btn--danger ui-btn--md" x-ref="confirmGo" x-on:click="runConfirm()" x-text="confirm.label"></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection
