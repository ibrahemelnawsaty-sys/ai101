{{--
    Trainer submissions board + quick grading form.

    Feedback is MANDATORY and at least 10 characters. The client hint below mirrors the
    rule; the rule itself lives in the FormRequest and in the evaluations table check
    constraint, so a direct POST without feedback is rejected too.

    A trainer only ever sees their own cohorts — the query is scoped by the
    cohort.scope middleware and the policy, never by this template.

    @see PRD §9.11.3, §9.15 · BR-12, BR-13, BR-14, BR-23
--}}
@extends('layouts.app')

@section('title', __('trainer.submissions.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('trainer.submissions.error_title')"
            :description="__('trainer.submissions.error_body')"
            :action-label="__('app.retry')" :action-href="route('trainer.submissions')" />
    @else

        {{-- Counters ---------------------------------------------------------- --}}
        <div class="dgrid dgrid--stats">
            @if (is_null($stats))
                @for ($i = 0; $i < 4; $i++)
                    <x-ui.card><x-ui.skeleton height="var(--s7)" width="var(--s14)" /><x-ui.skeleton height="var(--s3)" width="70%" class="u-mt-2" /></x-ui.card>
                @endfor
            @else
                <x-ui.stat-card :value="$stats->submitted" :label="__('trainer.submissions.stat_submitted')" />
                <x-ui.stat-card variant="warning" :value="$stats->late" :label="__('trainer.submissions.stat_late')" />
                <x-ui.stat-card variant="error" :value="$stats->missing" :label="__('trainer.submissions.stat_missing')" />
                <x-ui.stat-card variant="brand" :value="$stats->awaitingGrading" :label="__('trainer.submissions.stat_awaiting')" />
            @endif
        </div>

        {{-- Table --------------------------------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" flush>
            <div class="tablebar">
                <form method="GET" action="{{ route('trainer.submissions') }}" class="toolbar__filters">
                    <x-ui.search-input name="q" :value="request('q')" :placeholder="__('trainer.submissions.search')" />
                    <x-ui.select name="assignment" :label="__('trainer.submissions.filter_assignment')" :options="$assignmentOptions" :value="request('assignment')" />
                    <x-ui.select name="status" :label="__('trainer.submissions.filter_status')" :options="$statusOptions" :value="request('status')" />
                    <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
                </form>
                <div class="toolbar__end">
                    {{-- The reminder addresses ONE assignment and goes to everyone who
                         has not handed it in (PRD §9.11.3), so it appears once the board
                         is filtered to a published, still-open assignment with someone
                         left to remind — the controller decides, the view only asks. --}}
                    @if ($remindAssignmentId)
                        <form method="POST" action="{{ route('trainer.submissions.remind', ['assignment' => $remindAssignmentId]) }}">
                            @csrf
                            <x-ui.button variant="secondary" size="sm" icon="bell" type="submit">{{ __('trainer.submissions.remind') }}</x-ui.button>
                        </form>
                    @endif
                    <x-ui.button variant="secondary" size="sm" icon="down"
                        :href="$bulkDownloadHref"
                        :disabled="is_null($bulkDownloadHref)">{{ __('trainer.submissions.bulk_download') }}</x-ui.button>
                    <x-ui.button variant="secondary" size="sm"
                        :href="route('trainer.submissions.export', request()->query())">{{ __('app.export_excel') }}</x-ui.button>
                </div>
            </div>

            @if (is_null($rows))
                <div class="tscroll">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 6; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-1)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s23)" /></td>
                                    <td><x-ui.skeleton height="var(--s6)" width="var(--s19)" rounded="full" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s12)" /></td>
                                    <td><x-ui.skeleton height="var(--s9)" width="var(--s17)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($rows->isEmpty())
                <x-ui.empty-state icon="file"
                    :title="request()->hasAny(['q', 'assignment', 'status']) ? __('trainer.submissions.no_match_title') : __('trainer.submissions.empty_title')"
                    :description="request()->hasAny(['q', 'assignment', 'status']) ? __('trainer.submissions.no_match_body') : __('trainer.submissions.empty_body')"
                    :action-label="request()->hasAny(['q', 'assignment', 'status']) ? __('app.clear_filters') : null"
                    :action-href="request()->hasAny(['q', 'assignment', 'status']) ? route('trainer.submissions') : null" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('trainer.submissions.title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('trainer.col_participant') }}</th>
                                <th scope="col">{{ __('trainer.submissions.col_submitted_at') }}</th>
                                <th scope="col">{{ __('trainer.submissions.col_file') }}</th>
                                <th scope="col">{{ __('trainer.submissions.col_state') }}</th>
                                <th scope="col">{{ __('grades.score') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr @class(['is-selected' => $row->id === $selected?->id])>
                                    <th scope="row">
                                        <span class="cellpair">
                                            <x-ui.avatar size="sm" :name="$row->participantName" />
                                            {{ $row->participantName }}
                                        </span>
                                    </th>
                                    <td class="u-num u-nowrap">
                                        {{ $row->submittedAt ? \App\Support\Dates::dateTime($row->submittedAt) : '—' }}
                                    </td>
                                    <td>
                                        @if ($row->fileLabel && $row->downloadUrl)
                                            <a href="{{ $row->downloadUrl }}" class="cellpair">
                                                <x-ui.icon name="file" />
                                                <span dir="ltr">{{ $row->fileLabel }}</span>
                                            </a>
                                        @elseif ($row->fileLabel)
                                            {{-- No signed-download route for a submitted file exists yet;
                                                 the name is shown plainly rather than as a dead link. --}}
                                            <span class="cellpair">
                                                <x-ui.icon name="file" />
                                                <span dir="ltr">{{ $row->fileLabel }}</span>
                                            </span>
                                        @else
                                            <span class="u-muted">{{ __('app.none') }}</span>
                                        @endif
                                    </td>
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
                                            :href="route('trainer.submissions', array_merge(request()->query(), [$selectedParam => $row->id]))">
                                            {{ $row->isGraded ? __('trainer.submissions.revise') : __('trainer.submissions.grade') }}
                                        </x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$rows" />
            @endif
        </x-ui.card>

        {{-- Quick grading form -------------------------------------------------- --}}
        @if ($selected)
            <x-ui.card class="dc--span u-mt-4" icon="badge"
                :title="__('trainer.grading.title', ['name' => $selected->participantName])">

                @if ($selected->isGraded)
                    <div class="note note--warn">
                        <b>{{ __('trainer.grading.revising_title') }}</b>
                        {{ __('trainer.grading.revising_body') }}
                    </div>
                @endif

                <div class="f2">
                    <div>
                        <h3 class="abrief__sub">{{ __('trainer.grading.submission') }}</h3>
                        <ul class="filelist">
                            @foreach ($selected->files as $file)
                                <li>
                                    @if ($file->downloadUrl)
                                        <a href="{{ $file->downloadUrl }}">
                                            <x-ui.icon name="file" />
                                            <span dir="ltr">{{ $file->name }}</span>
                                            <small class="u-num">{{ $file->sizeLabel }}</small>
                                        </a>
                                    @else
                                        <span>
                                            <x-ui.icon name="file" />
                                            <span dir="ltr">{{ $file->name }}</span>
                                            <small class="u-num">{{ $file->sizeLabel }}</small>
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
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

                    {{-- Recording and revising are different endpoints: BR-14 amends an
                         EVALUATION and demands a written reason, and the record
                         endpoint refuses a submission that already has a mark. The
                         form posted to `grade` in both cases, so a trainer who
                         mis-keyed a score could never correct it — they typed the
                         reason, pressed record, and got a conflict with the reason
                         discarded and the old mark untouched (D-65). --}}
                    <form method="POST"
                        action="{{ $selected->isRevision
                            ? route('trainer.submissions.revise', $selected->evaluationId)
                            : route('trainer.submissions.grade', $selected->id) }}"
                        x-data="{ feedback: @js(old('feedback', $selected->feedback ?? '')) }">
                        @csrf
                        @if ($selected->isRevision)
                            @method('PATCH')
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

                        @if ($selected->isGraded)
                            {{-- BR-14: revising a recorded score requires a written reason. --}}
                            <x-ui.textarea name="revision_reason" rows="2" required minlength="10"
                                :label="__('grades.revision_reason')"
                                :hint="__('grades.revision_reason_hint')"
                                :value="old('revision_reason')" />
                        @endif

                        <div class="row__acts">
                            <x-ui.button variant="primary" size="sm" type="submit">{{ __('trainer.grading.record') }}</x-ui.button>
                            <x-ui.button variant="secondary" size="sm" type="submit"
                                name="next" value="1">{{ __('trainer.grading.record_and_next') }}</x-ui.button>
                            <x-ui.button variant="ghost" size="sm"
                                :href="route('trainer.submissions', request()->except($selectedParam))">{{ __('app.cancel') }}</x-ui.button>
                        </div>
                    </form>
                </div>
            </x-ui.card>
        @endif

        {{-- BR-11 sanity warning: assignment maxima must add up to 50. --}}
        @if ($totalsWarning)
            <div class="note note--warn u-mt-4" role="status">
                <b>{{ __('trainer.grading.totals_warning_title') }}</b>
                {{ __('trainer.grading.totals_warning_body', ['current' => $totalsWarning->current, 'expected' => $totalsWarning->expected]) }}
            </div>
        @endif
    @endif
@endsection
