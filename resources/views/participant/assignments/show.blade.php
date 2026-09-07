{{--
    Assignment detail and submission — drag & drop with a real XHR upload progress bar.

    The submit endpoint enforces everything shown here: deadline, allow_late, MIME sniffing
    from file CONTENT, size and count limits, and version increment (BR-19 keeps every
    previous version). The disabled button below is a courtesy, not a guard.

    @see PRD §9.11 · BR-17, BR-18, BR-19
--}}
@extends('layouts.app')

@section('title', $assignment?->title ?? __('nav.assignments'))
@section('subtitle', $assignment?->weekTitle ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('assignments.error_title')"
            :description="__('assignments.error_body')"
            :action-label="__('nav.assignments')" :action-href="route('assignments.index')" />
    @elseif (is_null($assignment))
        <x-ui.card>
            <x-ui.skeleton height="var(--s6)" width="70%" />
            <x-ui.skeleton height="var(--s3)" width="90%" class="u-mt-2" />
            <x-ui.skeleton height="var(--s3)" width="84%" class="u-mt-1" />
            <x-ui.skeleton height="var(--d-2)" width="100%" class="u-mt-4" rounded="lg" />
        </x-ui.card>
    @else
        {{-- Brief --------------------------------------------------------------- --}}
        <x-ui.card class="dc--span">
            <div class="abrief">
                <div class="abrief__main">
                    <div class="abrief__pills">
                        <x-ui.pill variant="primary">{{ $assignment->isMandatory ? __('assignments.mandatory') : __('assignments.optional') }}</x-ui.pill>
                        <x-ui.pill variant="neutral"><span class="u-num">{{ $assignment->maxScore }}</span> {{ trans_choice('grades.points', $assignment->maxScore) }}</x-ui.pill>
                        <x-ui.pill :variant="$assignment->urgencyVariant" :icon="$assignment->urgencyIcon">{{ $assignment->remainingLabel }}</x-ui.pill>
                    </div>
                    <h2 class="abrief__t">{{ $assignment->headline }}</h2>
                    <div class="prose">{{ $assignment->description }}</div>

                    @if ($assignment->requirements->isNotEmpty())
                        <h3 class="abrief__sub">{{ __('assignments.requirements') }}</h3>
                        <ul class="prose">
                            @foreach ($assignment->requirements as $requirement)
                                <li>{{ $requirement }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($assignment->attachments->isNotEmpty())
                        <h3 class="abrief__sub">{{ __('assignments.trainer_attachments') }}</h3>
                        <ul class="filelist">
                            @foreach ($assignment->attachments as $attachment)
                                {{-- Temporary signed URL, valid 15 minutes, issued after the policy check. --}}
                                <li>
                                    <a href="{{ $attachment->downloadUrl }}">
                                        <x-ui.icon name="file" />
                                        {{ $attachment->name }}
                                        <small class="u-num">{{ $attachment->sizeLabel }}</small>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="abrief__due abrief__due--{{ $assignment->urgencyVariant }}">
                    <span>{{ __('assignments.deadline') }}</span>
                    <b class="u-num">{{ \App\Support\Dates::longDate($assignment->dueAt) }}</b>
                    <span class="u-num">{{ \App\Support\Dates::time12($assignment->dueAt) }}</span>
                </div>
            </div>
        </x-ui.card>

        {{-- Submission ---------------------------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="up" :title="__('assignments.your_submission')">
            @if ($submission && $submission->isGraded)
                <div class="note note--ok">
                    <b>{{ __('grades.trainer_feedback', ['trainer' => $submission->graderName]) }}</b>
                    {{-- The full feedback, never truncated (PRD §9.15.3). --}}
                    {{ $submission->feedback }}
                </div>
                <div class="row">
                    <div class="row__m">
                        <b>{{ __('grades.recorded_score') }}</b>
                        <span>{{ __('grades.recorded_on', ['date' => \App\Support\Dates::longDate($submission->gradedAt)]) }}</span>
                    </div>
                    <div class="row__e">
                        <b class="row__score"><span class="u-num">{{ $submission->score }}</span><small class="u-num"> / {{ $assignment->maxScore }}</small></b>
                    </div>
                </div>
            @endif

            @if ($submission)
                <div class="note">
                    <b>{{ __('assignments.current_submission', ['version' => $submission->version]) }}</b>
                    <span class="u-num">{{ \App\Support\Dates::dateTime($submission->submittedAt) }}</span>
                    @if ($submission->isLate)
                        <x-ui.pill variant="warning" icon="clock">{{ __('assignments.late') }}</x-ui.pill>
                    @endif
                </div>
                <ul class="filelist">
                    @foreach ($submission->files as $file)
                        <li>
                            <a href="{{ $file->downloadUrl }}">
                                <x-ui.icon name="file" />
                                {{ $file->name }}
                                <small class="u-num">{{ $file->sizeLabel }}</small>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if (! $canSubmit)
                <x-ui.empty-state icon="lock" variant="muted"
                    :title="__('assignments.closed_title')"
                    :description="$closedReason"
                    :action-label="__('nav.assignments')" :action-href="route('assignments.index')" />
            @else
                <form method="POST"
                    action="{{ route('assignments.submit', $assignment->id) }}"
                    enctype="multipart/form-data"
                    x-data="atharUploader({
                        endpoint: '{{ route('assignments.submit', $assignment->id) }}',
                        maxFiles: {{ $assignment->maxFiles }},
                        maxBytes: {{ $assignment->maxFileBytes }},
                        replaceWarning: @js(__('assignments.replace_warning'))
                    })"
                    x-on:submit.prevent="send()">
                    @csrf

                    {{-- Drop zone; the visible button keeps it operable by keyboard. --}}
                    <div class="drop"
                        x-bind:class="{ 'is-over': dragging }"
                        x-on:dragover.prevent="dragging = true"
                        x-on:dragleave.prevent="dragging = false"
                        x-on:drop.prevent="accept($event.dataTransfer.files); dragging = false">
                        <div class="drop__ic" aria-hidden="true"><x-ui.icon name="up" /></div>
                        <b>{{ __('assignments.drop_here') }}</b>
                        <span>
                            {{ __('assignments.accepted_types') }}
                            · {{ __('assignments.size_limit', ['size' => $assignment->maxFileSizeLabel]) }}
                            · {{ trans_choice('assignments.file_limit', $assignment->maxFiles, ['count' => $assignment->maxFiles]) }}
                        </span>
                        <x-ui.button variant="secondary" size="sm" type="button"
                            x-on:click="$refs.picker.click()">{{ __('assignments.choose_files') }}</x-ui.button>
                        <input type="file" name="files[]" multiple class="sr" x-ref="picker"
                            x-on:change="accept($event.target.files)"
                            aria-label="{{ __('assignments.choose_files') }}">
                    </div>

                    {{-- Real per-file progress driven by XHR upload events. --}}
                    <template x-for="file in files" x-bind:key="file.key">
                        <div class="up">
                            <div class="up__hd">
                                <x-ui.icon name="file" />
                                <b x-text="file.name"></b>
                                <span class="u-num" x-text="file.percent + '%'"></span>
                                <x-ui.button variant="ghost" size="sm" type="button"
                                    x-on:click="cancel(file)"
                                    x-show="file.percent < 100">{{ __('app.cancel') }}</x-ui.button>
                            </div>
                            <div class="pbar">
                                <div class="pbar__f" x-bind:style="`inline-size:${file.percent}%`"></div>
                            </div>
                            <div class="up__ft">
                                <span class="u-num" x-text="file.sizeLabel"></span>
                                <span x-text="file.stateLabel"></span>
                            </div>
                        </div>
                    </template>

                    <x-ui.input name="github_url" type="url" dir="ltr"
                        :label="__('assignments.github_url')"
                        :hint="__('assignments.github_hint')"
                        :value="old('github_url', $submission?->githubUrl)"
                        placeholder="https://github.com/" />

                    <x-ui.textarea name="note" rows="3"
                        :label="__('assignments.note_to_trainer')"
                        :value="old('note', $submission?->note)"
                        :placeholder="__('assignments.note_placeholder')" />

                    {{-- Enabled only once a file or a GitHub URL exists (server re-checks). --}}
                    <div class="row__acts">
                        <x-ui.button variant="primary" type="submit"
                            x-bind:disabled="! ready"
                            x-bind:aria-busy="uploading">{{ __('assignments.submit_action') }}</x-ui.button>
                        <x-ui.button variant="secondary" type="submit"
                            name="draft" value="1">{{ __('assignments.save_draft') }}</x-ui.button>
                    </div>

                    <p class="hint" role="status" aria-live="polite">
                        <x-ui.icon name="info" />
                        {{ __('assignments.resubmit_keeps_versions') }}
                    </p>
                </form>
            @endif
        </x-ui.card>

        {{-- Version history ------------------------------------------------------ --}}
        @if ($versions && $versions->isNotEmpty())
            <x-ui.card class="dc--span u-mt-4" icon="clock" :title="__('assignments.versions_title')">
                @foreach ($versions as $version)
                    <div class="row">
                        <x-ui.pill variant="neutral"><span class="u-num">{{ $version->number }}</span></x-ui.pill>
                        <div class="row__m">
                            <b class="u-num">{{ \App\Support\Dates::dateTime($version->submittedAt) }}</b>
                            <span>{{ trans_choice('assignments.file_count', $version->fileCount, ['count' => $version->fileCount]) }}</span>
                        </div>
                    </div>
                @endforeach
            </x-ui.card>
        @endif
    @endif
@endsection
