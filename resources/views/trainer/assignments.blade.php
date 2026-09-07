{{--
    Trainer assignments — list plus the create/edit editor. A draft is invisible to
    participants until published; the visibility rule lives in the query scope, not here.

    @see PRD §9.11.3, §9.15.1 · BR-11, BR-23
--}}
@extends('layouts.app')

@section('title', __('trainer.assignments.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('trainer.assignments.error_title')"
            :description="__('trainer.assignments.error_body')"
            :action-label="__('app.retry')" :action-href="route('trainer.assignments')" />
    @else

        @if ($totalsWarning)
            <div class="note note--warn" role="status">
                <b>{{ __('trainer.grading.totals_warning_title') }}</b>
                {{ __('trainer.grading.totals_warning_body', ['current' => $totalsWarning->current, 'expected' => $totalsWarning->expected]) }}
            </div>
        @endif

        <div class="toolbar">
            <form method="GET" action="{{ route('trainer.assignments') }}" class="toolbar__filters">
                <x-ui.select name="week" :label="__('schedule.filter_week')" :options="$weekOptions" :value="request('week')" />
                <x-ui.select name="status" :label="__('trainer.assignments.filter_status')" :options="$statusOptions" :value="request('status')" />
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>
            <div class="toolbar__end">
                <x-ui.button variant="primary" size="sm"
                    :href="route('trainer.assignments', ['edit' => 'new'])">{{ __('trainer.assignments.create') }}</x-ui.button>
            </div>
        </div>

        <x-ui.card class="dc--span" flush>
            @if (is_null($assignments))
                <div class="tscroll">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 5; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-3)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s20)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-1)" /></td>
                                    <td><x-ui.skeleton height="var(--s6)" width="var(--s18)" rounded="full" /></td>
                                    <td><x-ui.skeleton height="var(--s9)" width="var(--s18)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($assignments->isEmpty())
                <x-ui.empty-state icon="file"
                    :title="__('trainer.assignments.empty_title')"
                    :description="__('trainer.assignments.empty_body')"
                    :action-label="__('trainer.assignments.create')"
                    :action-href="route('trainer.assignments', ['edit' => 'new'])" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('trainer.assignments.title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('trainer.assignments.col_title') }}</th>
                                <th scope="col">{{ __('schedule.filter_week') }}</th>
                                <th scope="col">{{ __('assignments.deadline') }}</th>
                                <th scope="col">{{ __('grades.score') }}</th>
                                <th scope="col">{{ __('trainer.assignments.col_submitted') }}</th>
                                <th scope="col">{{ __('app.status') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($assignments as $assignment)
                                <tr>
                                    <th scope="row">
                                        {{ $assignment->title }}
                                        @if ($assignment->isMandatory)
                                            <x-ui.pill variant="brand" size="sm">{{ __('assignments.mandatory') }}</x-ui.pill>
                                        @endif
                                    </th>
                                    <td>{{ $assignment->weekTitle }}</td>
                                    <td class="u-num u-nowrap">{{ \App\Support\Dates::dateTime($assignment->dueAt) }}</td>
                                    <td class="u-num">{{ $assignment->maxScore }}</td>
                                    <td class="u-num">{{ $assignment->submittedCount }} / {{ $assignment->cohortSize }}</td>
                                    <td><x-ui.pill :variant="$assignment->statusVariant">{{ $assignment->statusLabel }}</x-ui.pill></td>
                                    <td class="u-nowrap">
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('trainer.assignments', ['edit' => $assignment->id])">{{ __('app.edit') }}</x-ui.button>
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('trainer.submissions', ['assignment' => $assignment->id])">{{ __('trainer.submissions.title') }}</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$assignments" />
            @endif
        </x-ui.card>

        {{-- Editor ---------------------------------------------------------------- --}}
        @if ($editing)
            <x-ui.card class="dc--span u-mt-4" icon="badge"
                :title="$editing->exists ? __('trainer.assignments.edit_title') : __('trainer.assignments.create')">

                <form method="POST"
                    action="{{ $editing->exists ? route('trainer.assignments.update', $editing->id) : route('trainer.assignments.store') }}"
                    enctype="multipart/form-data">
                    @csrf
                    @if ($editing->exists)
                        @method('PATCH')
                    @endif

                    <x-ui.input name="title" required :label="__('trainer.assignments.col_title')"
                        :value="old('title', $editing->title)" />

                    <x-ui.textarea name="description" rows="6" required
                        :label="__('trainer.assignments.description')"
                        :hint="__('trainer.assignments.description_hint')"
                        :value="old('description', $editing->description)" />

                    <div class="f2">
                        <x-ui.select name="week_id" required :label="__('schedule.filter_week')"
                            :options="$weekOptions" :value="old('week_id', $editing->weekId)" />
                        <x-ui.input name="max_score" type="number" min="0" step="0.5" required
                            :label="__('trainer.assignments.max_score')"
                            :value="old('max_score', $editing->maxScore)" />
                        <x-ui.input name="due_at" type="datetime-local" dir="ltr" required
                            :label="__('assignments.deadline')"
                            :hint="__('app.riyadh_time_hint')"
                            :value="old('due_at', $editing->dueAtValue)" />
                    </div>

                    <div class="f2">
                        <x-ui.input name="max_files" type="number" min="1" max="20" required
                            :label="__('trainer.assignments.max_files')"
                            :value="old('max_files', $editing->maxFiles)" />
                        <x-ui.input name="max_file_mb" type="number" min="1" max="100" required
                            :label="__('trainer.assignments.max_file_mb')"
                            :value="old('max_file_mb', $editing->maxFileMb)" />
                    </div>

                    <div class="checks">
                        <x-ui.checkbox name="is_mandatory" value="1"
                            :checked="old('is_mandatory', $editing->isMandatory)"
                            :label="__('trainer.assignments.is_mandatory')" />
                        <x-ui.checkbox name="allow_late" value="1"
                            :checked="old('allow_late', $editing->allowLate)"
                            :label="__('trainer.assignments.allow_late')" />
                        <x-ui.checkbox name="show_github_field" value="1"
                            :checked="old('show_github_field', $editing->showGithubField)"
                            :label="__('trainer.assignments.show_github')" />
                    </div>

                    <x-ui.file-uploader name="attachments[]" multiple
                        :label="__('assignments.trainer_attachments')"
                        :hint="__('trainer.assignments.attachments_hint')" />

                    <div class="row__acts">
                        <x-ui.button variant="primary" type="submit"
                            name="status" value="published">{{ __('trainer.assignments.publish') }}</x-ui.button>
                        <x-ui.button variant="secondary" type="submit"
                            name="status" value="draft">{{ __('trainer.assignments.save_draft') }}</x-ui.button>
                        <x-ui.button variant="ghost"
                            :href="route('trainer.assignments')">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif
    @endif
@endsection
