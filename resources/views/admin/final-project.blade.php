{{--
    Admin · the final project's settings for one cohort at a time (D-109, D-110).

    Everything here used to have no screen at all: `title`, `brief`,
    `requirements`, `due_at` and `max_score` were seed-only columns, and
    opening the tab lived on the trainer's own screen. Both moved here, fully
    separate from trainer.finalProject — that screen only reads the brief and
    grades what was handed in.

    Three states beyond loading/error: no cohort exists yet · a cohort list
    with none picked · a picked cohort's settings, existing or blank.

    D-121 — under the settings, the hand-in form's fields: the list in order,
    the server's upload limits, the editor (?field=new|{id}) and the removal
    prompt (?remove={id}). Every figure and every decision here comes from
    SubmissionFieldsPanel; every write goes through its own policy.

    D-127 — the last box makes the project AVAILABLE; publishing it is the
    cohort's primary coordinator's press. Under the settings, the guide card:
    each language's state, preview and availability, a copy from another
    cohort, and on ?guide=ar|en the editor with its paged history. Every figure
    comes from FinalProjectGuidePanel.

    @see PRD §9.14 · BR-15, BR-16, BR-23, BR-31 · D-109, D-110, D-121, D-127
--}}
@extends('layouts.app')

@section('title', __('admin.final_project.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.finalProject.index')" />
    @elseif (count($cohortOptions) === 0)
        <x-ui.empty-state icon="spark"
            :title="__('admin.final_project.no_cohort_title')"
            :description="__('admin.final_project.no_cohort_body')"
            :action-label="__('admin.final_project.no_cohort_action')" :action-href="route('admin.cohorts.index')" />
    @else
        <p class="form__note">{{ __('admin.final_project.intro') }}</p>

        <x-ui.card class="dc--span u-mt-4" icon="spark">
            <form method="GET" action="{{ route('admin.finalProject.index') }}" class="toolbar__filters">
                <x-ui.select name="cohort" required :label="__('admin.final_project.fields.cohort')"
                    :options="$cohortOptions" :value="$selectedCohortId" />
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>
        </x-ui.card>

        @if ($settings === null)
            <x-ui.empty-state icon="spark" class="u-mt-4"
                :title="__('admin.final_project.pick_cohort_title')"
                :description="__('admin.final_project.pick_cohort_body')" />
        @else
            <x-ui.card class="dc--span u-mt-4" icon="spark"
                :title="$settings->exists ? $settings->title : __('admin.final_project.title')">

                @if ($settings->exists)
                    <x-slot:action>
                        @if ($settings->isUnlocked)
                            <x-ui.pill variant="success" icon="check">{{ __('admin.final_project.states.published') }}</x-ui.pill>
                        @elseif ($settings->isAvailable)
                            <x-ui.pill variant="info" icon="clock">{{ __('admin.final_project.states.available') }}</x-ui.pill>
                        @else
                            <x-ui.pill variant="neutral" icon="lock">{{ __('admin.final_project.states.not_available') }}</x-ui.pill>
                        @endif
                    </x-slot:action>
                @endif

                @unless ($hasPrimaryCoordinator)
                    <div class="note note--warn" role="status">
                        <x-ui.icon name="warn" />
                        <p>
                            {{ __('admin.final_project.no_primary_coordinator') }}
                            <a href="{{ route('admin.cohorts.index') }}">{{ __('admin.final_project.no_primary_coordinator_action') }}</a>
                        </p>
                    </div>
                @endunless

                <form method="POST" action="{{ route('admin.finalProject.store') }}">
                    @csrf
                    <input type="hidden" name="cohort_id" value="{{ $selectedCohortId }}">

                    <x-ui.input name="title" required :label="__('admin.final_project.fields.title')"
                        :value="old('title', $settings->title)" />

                    <x-ui.textarea name="brief" rows="4" required
                        :label="__('admin.final_project.fields.brief')"
                        :value="old('brief', $settings->brief)" />

                    <x-ui.textarea name="requirements" rows="4"
                        :label="__('admin.final_project.fields.requirements')"
                        :value="old('requirements', $settings->requirementsLines)" />

                    <div class="f2">
                        <x-ui.input name="due_at" type="datetime-local" dir="ltr" required
                            :label="__('admin.final_project.fields.due_at')"
                            :hint="__('app.riyadh_time_hint')"
                            :value="old('due_at', $settings->dueAtValue)" />
                        <x-ui.input name="max_score" type="number" min="1" max="1000" required
                            :label="__('admin.final_project.fields.max_score')"
                            :value="old('max_score', $settings->maxScore)" />
                    </div>

                    <x-ui.checkbox name="allow_late" value="1"
                        :checked="old('allow_late', $settings->allowLate)"
                        :label="__('admin.final_project.fields.allow_late')"
                        :hint="__('admin.final_project.allow_late_hint')" />

                    <div class="row__acts">
                        <x-ui.button variant="primary" type="submit">{{ __('admin.final_project.save') }}</x-ui.button>
                    </div>
                </form>

                {{-- D-127 · availability: its own press, never part of the save above --}}
                <h3 class="abrief__sub" id="availability">{{ __('admin.final_project.availability_title') }}</h3>
                @if (! $settings->exists)
                    <p class="form__note">{{ __('admin.final_project.availability_save_first') }}</p>
                @else
                    <p class="form__note">{{ __('admin.final_project.is_available_hint') }}</p>

                    @if ($settings->isAvailable && $settings->availableByName)
                        <p class="footnote">
                            {{ __('admin.final_project.available_note', [
                                'name' => $settings->availableByName,
                                'date' => \App\Support\Dates::dateTime($settings->availableAt),
                            ]) }}
                        </p>
                    @endif

                    @if ($settings->isUnlocked && $settings->unlockedByName)
                        <p class="footnote">
                            {{ __('admin.final_project.unlocked_note', [
                                'name' => $settings->unlockedByName,
                                'date' => \App\Support\Dates::dateTime($settings->unlockedAt),
                            ]) }}
                        </p>
                    @endif

                    @if (! $settings->isAvailable)
                        <form method="POST" action="{{ route('admin.finalProject.availability', $settings->id) }}" class="row__acts">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="available" value="1">
                            <x-ui.button variant="primary" type="submit" icon="check">{{ __('admin.final_project.make_available') }}</x-ui.button>
                        </form>
                    @elseif ($settings->isUnlocked && $handInCount > 0 && ! $confirmingWithdraw)
                        <div class="row__acts">
                            <x-ui.button variant="secondary" icon="lock"
                                :href="route('admin.finalProject.index', ['cohort' => $selectedCohortId, 'confirm' => 'withdraw']).'#availability'">{{ __('admin.final_project.withdraw') }}</x-ui.button>
                        </div>
                    @elseif (! $confirmingWithdraw)
                        <form method="POST" action="{{ route('admin.finalProject.availability', $settings->id) }}" class="row__acts">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="available" value="0">
                            <x-ui.button variant="secondary" type="submit" icon="lock">{{ __('admin.final_project.withdraw') }}</x-ui.button>
                        </form>
                    @endif

                    @error('confirmed')
                        <p class="ui-field__error" role="alert">{{ $message }}</p>
                    @enderror
                @endif
            </x-ui.card>

            {{-- Confirming a withdrawal that locks a project with hand-ins ------- --}}
            @if ($confirmingWithdraw)
                <x-ui.card class="dc--span u-mt-4" icon="warn" :title="__('admin.final_project.confirm_withdraw_title')">
                    <div class="note note--warn" role="alert">
                        <x-ui.icon name="warn" />
                        <p>{{ __('admin.final_project.confirm_withdraw_body', ['count' => $handInLabel]) }}</p>
                    </div>

                    <form method="POST" action="{{ route('admin.finalProject.availability', $settings->id) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="available" value="0">
                        <input type="hidden" name="confirmed" value="1">
                        <div class="row__acts">
                            <x-ui.button variant="danger" type="submit">{{ __('admin.final_project.confirm_withdraw_action') }}</x-ui.button>
                            <x-ui.button variant="ghost"
                                :href="route('admin.finalProject.index', ['cohort' => $selectedCohortId]).'#availability'">{{ __('app.cancel') }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            {{-- D-127 · the guide --------------------------------------------------- --}}
            @if ($guidePanel !== null)
                @include('admin.partials.final-project-guide', ['guidePanel' => $guidePanel])
            @endif

            {{-- D-121 · the hand-in form's fields --------------------------------- --}}
            @if ($fieldsPanel === null)
                <x-ui.card class="dc--span u-mt-4" icon="up" id="submission-fields"
                    :title="__('admin.final_project.submission_fields.title')">
                    <p class="form__note">{{ __('admin.final_project.submission_fields.save_project_first') }}</p>
                </x-ui.card>
            @else
                <x-ui.card class="dc--span u-mt-4" icon="up" id="submission-fields"
                    :title="__('admin.final_project.submission_fields.title')">
                    <x-slot:action>
                        <x-ui.button variant="primary" size="sm" icon="spark"
                            :href="route('admin.finalProject.index', ['cohort' => $selectedCohortId, 'field' => 'new']).'#field-editor'">{{ __('admin.final_project.submission_fields.add') }}</x-ui.button>
                    </x-slot:action>

                    <p class="form__note">{{ __('admin.final_project.submission_fields.intro') }}</p>
                    <p class="hint"><x-ui.icon name="info" /><span>{{ $fieldsPanel->limitsNote }}</span></p>

                    @if ($fieldsPanel->heavyWarning)
                        <div class="note note--warn u-mt-2" role="status">
                            <x-ui.icon name="warn" />
                            <p>{{ $fieldsPanel->heavyWarning }}</p>
                        </div>
                    @endif

                    @if ($fieldsPanel->isEmpty)
                        <x-ui.empty-state icon="up" class="u-mt-4"
                            :title="__('admin.final_project.submission_fields.empty_title')"
                            :description="__('admin.final_project.submission_fields.empty_body')"
                            :action-label="__('admin.final_project.submission_fields.add')"
                            :action-href="route('admin.finalProject.index', ['cohort' => $selectedCohortId, 'field' => 'new']).'#field-editor'" />
                    @else
                        <ol class="reslist u-mt-4" role="list">
                            @foreach ($fieldsPanel->rows as $row)
                                <li @class(['row', 'is-selected' => $row->isSelected])>
                                    <div class="row__m">
                                        <b><span class="u-num">{{ $row->number }}.</span> {{ $row->label }}</b>
                                        <span class="u-inline">
                                            <x-ui.pill variant="info" :icon="$row->typeIcon">{{ $row->typeLabel }}</x-ui.pill>
                                            <x-ui.pill :variant="$row->requiredVariant">{{ $row->requiredLabel }}</x-ui.pill>
                                        </span>
                                        @if ($row->description)
                                            <span class="note__body">{{ $row->description }}</span>
                                        @endif
                                        <span class="note__body">{{ $row->rulesLabel }}</span>
                                    </div>

                                    <div class="row__e row__acts">
                                        <form method="POST" action="{{ route('admin.finalProject.fields.move', [$fieldsPanel->projectId, $row->id]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="direction" value="up">
                                            <x-ui.button variant="ghost" size="sm" type="submit" icon="chevup"
                                                :state="$row->canMoveUp ? 'default' : 'disabled'"><span class="ui-sr">{{ $row->moveUpLabel }}</span></x-ui.button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.finalProject.fields.move', [$fieldsPanel->projectId, $row->id]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="direction" value="down">
                                            <x-ui.button variant="ghost" size="sm" type="submit" icon="chevdown"
                                                :state="$row->canMoveDown ? 'default' : 'disabled'"><span class="ui-sr">{{ $row->moveDownLabel }}</span></x-ui.button>
                                        </form>
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('admin.finalProject.index', ['cohort' => $selectedCohortId, 'field' => $row->id]).'#field-editor'">{{ __('app.edit') }}</x-ui.button>
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('admin.finalProject.index', ['cohort' => $selectedCohortId, 'remove' => $row->id]).'#field-removal'">{{ __('admin.final_project.submission_fields.remove') }}</x-ui.button>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </x-ui.card>

                {{-- Removal prompt ---------------------------------------------------- --}}
                @if ($fieldsPanel->removal)
                    <x-ui.card class="dc--span u-mt-4" icon="warn" id="field-removal"
                        :title="$fieldsPanel->removal['title']">
                        <div class="note note--warn">
                            {{ __('admin.final_project.submission_fields.remove_body') }}
                        </div>

                        <form method="POST"
                            action="{{ route('admin.finalProject.fields.destroy', [$fieldsPanel->projectId, $fieldsPanel->removal['id']]) }}">
                            @csrf
                            @method('DELETE')

                            <div class="row__acts">
                                <x-ui.button variant="danger" type="submit">{{ __('admin.final_project.submission_fields.remove_action') }}</x-ui.button>
                                <x-ui.button variant="ghost"
                                    :href="route('admin.finalProject.index', ['cohort' => $selectedCohortId]).'#submission-fields'">{{ __('app.cancel') }}</x-ui.button>
                            </div>
                        </form>
                    </x-ui.card>
                @endif

                {{-- Field editor ------------------------------------------------------ --}}
                @if ($fieldsPanel->editor)
                    <x-ui.card class="dc--span u-mt-4" icon="up" id="field-editor" :title="$fieldsPanel->editor->title">
                        <form method="POST"
                            action="{{ $fieldsPanel->editor->exists
                                ? route('admin.finalProject.fields.update', [$fieldsPanel->projectId, $fieldsPanel->editor->id])
                                : route('admin.finalProject.fields.store', $fieldsPanel->projectId) }}"
                            x-data="{ type: @js(old('type', $fieldsPanel->editor->type)) }"
                            x-on:change="if ($event.target.name === 'type') type = $event.target.value">
                            @csrf
                            @if ($fieldsPanel->editor->exists)
                                @method('PATCH')
                            @endif

                            <x-ui.radio name="type" variant="cards" required
                                :legend="__('admin.final_project.submission_fields.form.type')"
                                :options="$fieldsPanel->typeOptions"
                                :value="old('type', $fieldsPanel->editor->type)" />

                            <x-ui.input name="label" required maxlength="160"
                                :label="__('admin.final_project.submission_fields.form.label')"
                                :hint="__('admin.final_project.submission_fields.form.label_hint')"
                                :value="old('label', $fieldsPanel->editor->label)" />

                            <x-ui.textarea name="description" rows="2"
                                :label="__('admin.final_project.submission_fields.form.description')"
                                :hint="__('admin.final_project.submission_fields.form.description_hint')"
                                :value="old('description', $fieldsPanel->editor->description)" />

                            <x-ui.textarea name="tips" rows="3"
                                :label="__('admin.final_project.submission_fields.form.tips')"
                                :hint="__('admin.final_project.submission_fields.form.tips_hint')"
                                :value="old('tips', $fieldsPanel->editor->tipsLines)" />

                            <x-ui.checkbox name="is_required" value="1"
                                :checked="old('is_required', $fieldsPanel->editor->isRequired)"
                                :label="__('admin.final_project.submission_fields.form.is_required')"
                                :hint="__('admin.final_project.submission_fields.form.is_required_hint')" />

                            <div x-bind:hidden="type !== @js($fieldsPanel->fileType)"
                                @if (old('type', $fieldsPanel->editor->type) !== $fieldsPanel->fileType) hidden @endif>
                                <h3 class="abrief__sub">{{ __('admin.final_project.submission_fields.form.file_legend') }}</h3>
                                <p class="form__note">{{ __('admin.final_project.submission_fields.form.file_hint') }}</p>

                                <x-ui.checkbox name="formats" variant="cards"
                                    :legend="__('admin.final_project.submission_fields.form.formats')"
                                    :options="$fieldsPanel->formatOptions"
                                    :value="old('formats', $fieldsPanel->editor->formats)" />

                                <div class="f2">
                                    <x-ui.input name="max_megabytes" type="number" min="1" :max="$fieldsPanel->maxMegabytes"
                                        :label="__('admin.final_project.submission_fields.form.max_megabytes')"
                                        :hint="$fieldsPanel->maxMegabytesHint"
                                        :value="old('max_megabytes', $fieldsPanel->editor->maxMegabytes)" />
                                    <x-ui.input name="max_files" type="number" min="1" :max="$fieldsPanel->maxFiles"
                                        :label="__('admin.final_project.submission_fields.form.max_files')"
                                        :hint="$fieldsPanel->maxFilesHint"
                                        :value="old('max_files', $fieldsPanel->editor->maxFiles)" />
                                </div>
                            </div>

                            <div class="row__acts u-mt-4">
                                <x-ui.button variant="primary" type="submit">{{ __('admin.final_project.submission_fields.save') }}</x-ui.button>
                                <x-ui.button variant="ghost"
                                    :href="route('admin.finalProject.index', ['cohort' => $selectedCohortId]).'#submission-fields'">{{ __('app.cancel') }}</x-ui.button>
                            </div>
                        </form>
                    </x-ui.card>
                @endif
            @endif
        @endif
    @endif
@endsection
