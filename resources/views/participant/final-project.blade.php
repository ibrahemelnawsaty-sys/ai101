{{--
    Final project.

    LOCK CONTRACT (BR-16): when the project is locked the controller passes $project = null
    and $isUnlocked = false. NOTHING about the brief — title, description, criteria,
    attachments, deadline — reaches this template, so it cannot reach the browser.
    Viewing source before unlock reveals nothing but the locked placeholder.
    Direct access to the route is answered with 403 by the policy, not by hiding markup.

    @see PRD §9.14 · BR-16
--}}
@extends('layouts.app')

@section('title', __('nav.final_project'))
@section('subtitle', $isUnlocked ? __('project.subtitle_open') : __('project.subtitle_locked'))

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('project.error_title')"
            :description="__('project.error_body')"
            :action-label="__('app.retry')" :action-href="route('finalProject')" />

    @elseif (! $isUnlocked)
        {{-- Locked state. No project data exists in this response at all. --}}
        <x-ui.card class="dc--span">
            <x-ui.empty-state icon="lock" variant="muted"
                :title="__('project.locked_title')"
                :description="$expectedOpeningLabel
                    ? __('project.locked_body_with_date', ['date' => $expectedOpeningLabel])
                    : __('project.locked_body')"
                :action-label="__('nav.assignments')" :action-href="route('assignments.index')" />
        </x-ui.card>

    @elseif (is_null($project))
        <x-ui.card>
            <x-ui.skeleton height="var(--s6)" width="60%" />
            <x-ui.skeleton height="var(--s3)" width="92%" class="u-mt-2" />
            <x-ui.skeleton height="var(--s3)" width="80%" class="u-mt-1" />
            <x-ui.skeleton height="var(--d-2)" width="100%" rounded="lg" class="u-mt-4" />
        </x-ui.card>

    @else
        {{-- Brief -------------------------------------------------------------- --}}
        <x-ui.card class="dc--span" icon="badge" :title="$project->title">
            <x-slot:action>
                <x-ui.countdown :until="$project->dueAt" :server-now="$serverNow"
                    :label="__('project.time_left')" />
            </x-slot:action>

            <div class="prose">{{ $project->description }}</div>

            @if ($project->requirements->isNotEmpty())
                <h3 class="abrief__sub">{{ __('project.requirements') }}</h3>
                <ul class="prose">
                    @foreach ($project->requirements as $requirement)
                        <li>{{ $requirement }}</li>
                    @endforeach
                </ul>
            @endif

            <h3 class="abrief__sub">{{ __('project.criteria') }}</h3>
            @if ($project->criteria->isEmpty())
                <x-ui.empty-state icon="badge" size="sm"
                    :title="__('project.criteria_empty_title')"
                    :description="__('project.criteria_empty_body')" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('project.criteria') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('project.criterion') }}</th>
                                <th scope="col">{{ __('project.criterion_points') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($project->criteria as $criterion)
                                <tr>
                                    <td>{{ $criterion->title }}</td>
                                    <td class="u-num">{{ $criterion->maxScore }}</td>
                                </tr>
                            @endforeach
                            <tr class="atable__total">
                                <td>{{ __('project.total') }}</td>
                                <td class="u-num">{{ $project->maxScore }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($project->attachments->isNotEmpty())
                <h3 class="abrief__sub">{{ __('project.attachments') }}</h3>
                <ul class="filelist">
                    @foreach ($project->attachments as $attachment)
                        <li>
                            <a href="{{ $attachment->downloadUrl }}">
                                <x-ui.icon name="file" />{{ $attachment->name }}
                                <small class="u-num">{{ $attachment->sizeLabel }}</small>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        {{-- Grade and feedback, once evaluated -------------------------------- --}}
        @if ($evaluation)
            <x-ui.card class="dc--span u-mt-4" icon="check" :title="__('project.evaluation_title')">
                <div class="row">
                    <div class="row__m">
                        <b>{{ __('grades.recorded_score') }}</b>
                        <span>{{ __('grades.recorded_on', ['date' => \App\Support\Dates::longDate($evaluation->recordedAt)]) }}</span>
                    </div>
                    <div class="row__e">
                        <b class="row__score"><span class="u-num">{{ $evaluation->score }}</span><small class="u-num"> / {{ $project->maxScore }}</small></b>
                    </div>
                </div>
                <div class="note note--ok">
                    <b>{{ __('grades.trainer_feedback', ['trainer' => $evaluation->graderName]) }}</b>
                    {{-- Full feedback, never truncated. --}}
                    {{ $evaluation->feedback }}
                </div>
            </x-ui.card>
        @endif

        {{-- Submission --------------------------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="up" :title="__('project.your_submission')">
            @if ($submission)
                <div class="note">
                    <b>{{ __('assignments.current_submission', ['version' => $submission->version]) }}</b>
                    <span class="u-num">{{ \App\Support\Dates::dateTime($submission->submittedAt) }}</span>
                </div>
                <ul class="filelist">
                    @foreach ($submission->files as $file)
                        <li>
                            <a href="{{ $file->downloadUrl }}">
                                <x-ui.icon name="file" />{{ $file->name }}
                                <small class="u-num">{{ $file->sizeLabel }}</small>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if (! $canSubmit)
                <x-ui.empty-state icon="lock" variant="muted"
                    :title="__('project.submission_closed_title')"
                    :description="$closedReason" />
            @else
                {{-- A plain form, for the same reason as the assignment screen
                     (D-54): this was driven by an Alpine component named
                     atharUploader that nothing registers, and
                     x-on:submit.prevent cancelled the native submit before the
                     missing handler could throw. Half the marks in the whole
                     programme live in this project, and it could not be handed
                     in at all. --}}
                <form method="POST" action="{{ route('finalProject.submit') }}"
                    enctype="multipart/form-data">
                    @csrf

                    <div class="drop">
                        <div class="drop__ic" aria-hidden="true"><x-ui.icon name="up" /></div>
                        <b>{{ __('assignments.choose_files') }}</b>
                        <span>
                            {{ __('assignments.accepted_types') }}
                            · {{ __('assignments.size_limit', ['size' => $project->maxFileSizeLabel]) }}
                            · {{ trans_choice('assignments.file_limit', $project->maxFiles, ['count' => $project->maxFiles]) }}
                        </span>

                        <input type="file" name="files[]" multiple id="project-files">
                        <label class="sr" for="project-files">{{ __('assignments.choose_files') }}</label>

                        @error('files')
                            <p class="hint hint--error" role="alert">{{ $message }}</p>
                        @enderror
                        @error('files.*')
                            <p class="hint hint--error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <x-ui.input name="github_url" type="url" dir="ltr"
                        :label="__('assignments.github_url')"
                        :hint="__('assignments.github_hint')"
                        :value="old('github_url', $submission?->githubUrl)"
                        placeholder="https://github.com/" />

                    <x-ui.textarea name="description" rows="4"
                        :label="__('project.description_field')"
                        :value="old('description', $submission?->description)"
                        :placeholder="__('project.description_placeholder')" />

                    <div class="row__acts">
                        <x-ui.button variant="primary" type="submit"
                            x-bind:disabled="! ready"
                            x-bind:aria-busy="uploading">{{ __('project.submit_action') }}</x-ui.button>
                    </div>

                    <p class="hint">
                        <x-ui.icon name="info" />
                        {{ __('assignments.resubmit_keeps_versions') }}
                    </p>
                </form>
            @endif
        </x-ui.card>
    @endif
@endsection
