{{--
    Trainer · final project — opening it for the cohort, and grading it.

    BR-15, BR-16: the tab opens for a WHOLE cohort at once and only a trainer of
    that cohort or an administrator may open it. The switch below reflects that
    state; it does not decide it. The policy on UnlockFinalProjectRequest
    refuses a direct POST from anybody else whether or not this control was
    drawn (CONSTITUTION art. 5).

    BR-12, BR-13: the mark never exceeds the project's own ceiling and the
    feedback is mandatory at ten characters. The hint below mirrors both; the
    rules themselves live in StoreProjectEvaluationRequest and in the
    evaluations table's check constraint.

    Four states: error · loading skeleton shaped like the table · empty · normal.

    @see PRD §9.14, §9.15 · BR-12, BR-13, BR-15, BR-16, BR-23
--}}
@extends('layouts.app')

@section('title', __('trainer.final_project.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('trainer.final_project.error_title')"
            :description="__('trainer.final_project.error_body')"
            :action-label="__('app.retry')" :action-href="route('trainer.finalProject')" />
    @elseif ($project->isMissing)
        <x-ui.empty-state icon="folder"
            :title="__('trainer.final_project.missing_title')"
            :description="__('trainer.final_project.missing_body')" />
    @else

        {{-- The brief, and the switch that opens it (BR-15, BR-16) ------------- --}}
        <x-ui.card class="dc--span" icon="badge" :title="$project->title">
            <x-slot:action>
                <x-ui.pill :variant="$project->isUnlocked ? 'success' : 'neutral'"
                    :icon="$project->isUnlocked ? 'check' : 'lock'">
                    {{ $project->isUnlocked ? __('trainer.final_project.state_open') : __('trainer.final_project.state_locked') }}
                </x-ui.pill>
            </x-slot:action>

            <div class="f2">
                <div>
                    @if ($project->description)
                        <p class="note__body">{{ $project->description }}</p>
                    @endif

                    @if ($project->requirements !== [])
                        <h3 class="abrief__sub">{{ __('trainer.final_project.requirements') }}</h3>
                        <ul class="note__list">
                            @foreach ($project->requirements as $requirement)
                                <li>{{ $requirement }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($project->attachments !== [])
                        <h3 class="abrief__sub">{{ __('project.attachments') }}</h3>
                        <ul class="filelist">
                            @foreach ($project->attachments as $attachment)
                                <li>
                                    <span>
                                        <x-ui.icon name="file" />
                                        <span dir="ltr">{{ $attachment->name }}</span>
                                        <small class="u-num">{{ $attachment->sizeLabel }}</small>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div>
                    <dl class="deflist">
                        <div>
                            <dt>{{ __('trainer.final_project.due_at') }}</dt>
                            <dd class="u-num">{{ \App\Support\Dates::dateTime($project->dueAt) }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('trainer.final_project.max_score') }}</dt>
                            <dd class="u-num">{{ $project->maxScore }}</dd>
                        </div>
                        @if ($project->isUnlocked)
                            <div>
                                <dt>{{ __('app.status') }}</dt>
                                <dd>{{ __('trainer.final_project.opened_by', [
                                    'name' => $project->unlockedByName,
                                    'date' => \App\Support\Dates::dateTime($project->unlockedAt),
                                ]) }}</dd>
                            </div>
                        @endif
                    </dl>

                    <form method="POST" action="{{ route('trainer.finalProject.unlock', $project->id) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="is_unlocked" value="{{ $project->toggleValue }}">

                        <p class="hint">
                            <x-ui.icon name="warn" />
                            {{ __('trainer.final_project.open_hint') }}
                        </p>

                        <div class="row__acts">
                            <x-ui.button size="sm" type="submit"
                                :variant="$project->isUnlocked ? 'secondary' : 'primary'">
                                {{ $project->isUnlocked ? __('trainer.final_project.close_action') : __('trainer.final_project.open_action') }}
                            </x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        </x-ui.card>

        {{-- What has been handed in ------------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" flush
            :title="__('trainer.final_project.submissions_title')" icon="file">

            @if (is_null($submissions))
                <div class="tscroll">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 5; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-1)" /></td>
                                    <td><x-ui.skeleton height="var(--s6)" width="var(--s19)" rounded="full" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s12)" /></td>
                                    <td><x-ui.skeleton height="var(--s9)" width="var(--s17)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($submissions->isEmpty())
                <x-ui.empty-state icon="file"
                    :title="__('trainer.final_project.empty_title')"
                    :description="__('trainer.final_project.empty_body')" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('trainer.final_project.submissions_title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('trainer.col_participant') }}</th>
                                <th scope="col">{{ __('trainer.submissions.col_submitted_at') }}</th>
                                <th scope="col">{{ __('trainer.final_project.col_state') }}</th>
                                <th scope="col">{{ __('grades.score') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($submissions as $row)
                                <tr @class(['is-selected' => $row->id === ($selected->id ?? null)])>
                                    <th scope="row">
                                        <span class="cellpair">
                                            <x-ui.avatar size="sm" :name="$row->participantName" />
                                            {{ $row->participantName }}
                                        </span>
                                    </th>
                                    <td class="u-num u-nowrap">{{ \App\Support\Dates::dateTime($row->submittedAt) }}</td>
                                    <td>
                                        <x-ui.pill :variant="$row->stateVariant" :icon="$row->stateIcon">{{ $row->stateLabel }}</x-ui.pill>
                                    </td>
                                    <td>
                                        @if ($row->isGraded)
                                            <b class="row__score"><span class="u-num">{{ $row->score }}</span><small class="u-num"> / {{ $row->maxScore }}</small></b>
                                        @else
                                            <span class="u-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="u-nowrap">
                                        <x-ui.button size="sm"
                                            :variant="$row->isGraded ? 'secondary' : 'primary'"
                                            :href="route('trainer.finalProject', ['grade' => $row->id])">
                                            {{ $row->isGraded ? __('trainer.submissions.revise') : __('trainer.submissions.grade') }}
                                        </x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        {{-- Grading panel (BR-12, BR-13) ---------------------------------------- --}}
        @if ($selected)
            <x-ui.card class="dc--span u-mt-4" icon="badge"
                :title="__('trainer.grading.title', ['name' => $selected->participantName])">

                <div class="f2">
                    <div>
                        <h3 class="abrief__sub">{{ __('trainer.grading.submission') }}</h3>

                        @if ($selected->files === [])
                            <p class="u-muted">{{ __('app.none') }}</p>
                        @else
                            <ul class="filelist">
                                @foreach ($selected->files as $file)
                                    <li>
                                        {{-- No signed-download route for a submitted file exists yet,
                                             so the name is shown plainly rather than as a dead link. --}}
                                        <span>
                                            <x-ui.icon name="file" />
                                            <span dir="ltr">{{ $file->name }}</span>
                                            <small class="u-num">{{ $file->sizeLabel }}</small>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($selected->githubUrl)
                            <p><a href="{{ $selected->githubUrl }}" dir="ltr" target="_blank" rel="noopener nofollow">{{ $selected->githubUrl }}</a></p>
                        @endif

                        @if ($selected->note)
                            <div class="note">
                                <b>{{ __('trainer.grading.participant_note') }}</b>
                                <p class="note__body">{{ $selected->note }}</p>
                            </div>
                        @endif

                        <p class="footnote">
                            {{ __('assignments.current_submission', ['version' => $selected->version]) }} ·
                            <span class="u-num">{{ \App\Support\Dates::dateTime($selected->submittedAt) }}</span>
                        </p>
                    </div>

                    <form method="POST" action="{{ route('trainer.finalProject.grade', $selected->id) }}"
                        x-data="{ feedback: @js(old('feedback', $selected->feedback ?? '')) }">
                        @csrf

                        @if ($selected->isGraded)
                            <div class="note note--warn">
                                <b>{{ __('trainer.grading.revising_title') }}</b>
                                {{ __('trainer.grading.revising_body') }}
                            </div>
                        @endif

                        <x-ui.input name="score" type="number" inputmode="decimal" step="0.5"
                            min="0" :max="$selected->maxScore" required
                            :label="__('grades.score')"
                            :suffix="__('grades.out_of', ['max' => $selected->maxScore])"
                            :hint="__('trainer.grading.score_hint')"
                            :value="old('score', $selected->score)" />

                        <x-ui.textarea name="feedback" rows="4" required minlength="10"
                            :label="__('grades.feedback')"
                            :placeholder="__('trainer.grading.feedback_placeholder')"
                            x-model="feedback" />

                        {{-- Mirror of the server rule; the server is still the judge. --}}
                        <p class="hint" x-bind:class="feedback.trim().length < 10 ? 'hint--bad' : ''"
                            role="status" aria-live="polite">
                            <x-ui.icon name="warn" />
                            <span x-show="feedback.trim().length < 10">{{ __('grades.feedback_required_min', ['min' => 10]) }}</span>
                            <span x-show="feedback.trim().length >= 10" x-cloak>{{ __('grades.feedback_ok') }}</span>
                        </p>

                        <div class="row__acts">
                            <x-ui.button variant="primary" size="sm" type="submit">{{ __('trainer.grading.record') }}</x-ui.button>
                            <x-ui.button variant="ghost" size="sm"
                                :href="route('trainer.finalProject')">{{ __('app.cancel') }}</x-ui.button>
                        </div>
                    </form>
                </div>
            </x-ui.card>
        @endif
    @endif
@endsection
