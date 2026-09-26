{{--
    Final project.

    LOCK CONTRACT (BR-16): when the project is locked the controller passes $project = null
    and $isUnlocked = false. NOTHING about the brief — title, description, criteria,
    attachments, deadline, hand-in fields (D-121) — reaches this template, so it cannot
    reach the browser.
    Viewing source before unlock reveals nothing but the locked placeholder.
    Direct access to the route is answered with 403 by the policy, not by hiding markup.

    @see PRD §9.14 · BR-16, BR-19 · FR-PROJ-10 · D-121
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
                    variant="compact" :label="__('project.time_left')" />
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
        {{-- D-121: every item below is a field the general supervisor defined,
             in their order. What was handed in shows under the label it was
             asked by, each file with its own signed link. --}}
        <x-ui.card class="dc--span u-mt-4" icon="up" :title="__('project.your_submission')">
            @if ($receipt)
                {{-- D-122: the receipt of the newest version — its code, its QR
                     and the next step — with a link to the printable page. --}}
                @include('partials.hand-in-receipt', ['receipt' => $receipt])
                <div class="row__acts u-mt-2">
                    <x-ui.button variant="secondary" size="sm" :href="$receipt->url">{{ __('project.receipt.open') }}</x-ui.button>
                </div>
            @endif

            @if ($submission)
                <div class="note u-mt-4">
                    <b>{{ __('assignments.current_submission', ['version' => $submission->version]) }}</b>
                    <span class="u-num">{{ \App\Support\Dates::dateTime($submission->submittedAt) }}</span>
                </div>
                @include('partials.hand-in-answers', ['answers' => $submission->answers])
            @endif

            @if (! $canSubmit)
                <x-ui.empty-state icon="lock" variant="muted"
                    :title="__('project.submission_closed_title')"
                    :description="$closedReason" />
            @elseif ($handIn->isEmpty)
                <x-ui.empty-state icon="folder" variant="muted"
                    :title="__('project.fields_empty_title')"
                    :description="__('project.fields_empty_body')" />
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

                    <p class="form__note">{{ __('project.submission_intro') }}</p>

                    @if ($handIn->limitsNote)
                        <p class="hint"><x-ui.icon name="info" /><span>{{ $handIn->limitsNote }}</span></p>
                    @endif

                    @error('answers')
                        <p class="hint hint--error" role="alert">{{ $message }}</p>
                    @enderror

                    @foreach ($handIn->fields as $field)
                        @if ($field->isFile)
                            <div class="drop u-mt-4">
                                <div class="drop__ic" aria-hidden="true"><x-ui.icon name="up" /></div>
                                <b>
                                    {{ $field->label }}
                                    @if ($field->isRequired)
                                        <i class="ui-field__required" aria-hidden="true">*</i>
                                    @else
                                        <small>{{ $field->optionalMark }}</small>
                                    @endif
                                </b>
                                @if ($field->description)
                                    <span>{{ $field->description }}</span>
                                @endif
                                <span>{{ $field->formatsLabel }} · {{ $field->limitsLabel }}</span>

                                <input type="file" name="{{ $field->name }}" id="{{ $field->domId }}"
                                    accept="{{ $field->accept }}"
                                    @if ($field->isMultiple) multiple @endif
                                    @if ($field->isRequired) required @endif
                                    @error($field->errorKey) aria-invalid="true" @enderror>
                                <label class="sr" for="{{ $field->domId }}">{{ $field->label }}</label>

                                @if ($field->tips !== [])
                                    <ul class="note__list">
                                        @foreach ($field->tips as $tip)
                                            <li>{{ $tip }}</li>
                                        @endforeach
                                    </ul>
                                @endif

                                @error($field->errorKey)
                                    <p class="hint hint--error" role="alert">{{ $message }}</p>
                                @enderror
                            </div>
                        @else
                            <div class="u-mt-4">
                                @if ($field->isTextarea)
                                    <x-ui.textarea :name="$field->name" :id="$field->domId" rows="4"
                                        :required="$field->isRequired" :maxlength="$field->maxLength"
                                        :label="$field->label"
                                        :hint="$field->description"
                                        :error="$errors->first($field->errorKey)"
                                        :value="old($field->errorKey, $field->previousValue)" />
                                @else
                                    <x-ui.input :name="$field->name" :id="$field->domId" :type="$field->inputType"
                                        :ltr="$field->isLtr" :required="$field->isRequired" :maxlength="$field->maxLength"
                                        :label="$field->label"
                                        :hint="$field->description"
                                        :placeholder="$field->placeholder"
                                        :error="$errors->first($field->errorKey)"
                                        :value="old($field->errorKey, $field->previousValue)" />
                                @endif

                                @if ($field->tips !== [])
                                    <ul class="note__list">
                                        @foreach ($field->tips as $tip)
                                            <li>{{ $tip }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endif
                    @endforeach

                    <div class="row__acts u-mt-4">
                        <x-ui.button variant="primary" type="submit">{{ __('project.submit_action') }}</x-ui.button>
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
