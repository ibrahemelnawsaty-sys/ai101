{{--
    Admin · programmes.

    Create, edit and archive programmes. Archiving hides a programme from
    visitors and never deletes its data (PRD §7.8), so the destructive-sounding
    action is a status change and is announced as such.

    Everything an administrator can change here is content that BR-31 forbids
    hard-coding: name, summary, objectives, audience, certificates, hours.

    Four states: error · loading skeleton shaped like the table · empty · normal.

    @see PRD §9.18, §7.2 · BR-27, BR-31, BR-36
--}}
@extends('layouts.app')

@section('title', __('admin.programs.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.programs.index')" />
    @else

        <div class="toolbar">
            <form method="GET" action="{{ route('admin.programs.index') }}" class="toolbar__filters">
                <x-ui.search-input name="q" :value="request('q')"
                    :placeholder="__('app.search_placeholder')" />
                {{-- `state`, not `status`: the editor below has a `status` field of its own, and two
                     controls of one name shared an id and let a failed save rewrite this filter (D-147). --}}
                <x-ui.select clearable name="state" :label="__('admin.programs.fields.status')"
                    :options="$statusOptions" :value="request('state')" />
                <x-ui.button icon="filter" variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>
            <div class="toolbar__end">
                <x-ui.button icon="plus" variant="primary" size="sm"
                    :href="route('admin.programs.index', ['edit' => 'new'])">{{ __('admin.programs.create') }}</x-ui.button>
            </div>
        </div>

        <x-ui.card class="dc--span u-mt-4" flush>
            @if (is_null($programs))
                <div class="tscroll">
                    <table class="atable atable--skel">
                        <tbody>
                            @for ($i = 0; $i < 5; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-3)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-1)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s14)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s14)" /></td>
                                    <td><x-ui.skeleton height="var(--s6)" width="var(--s18)" rounded="full" /></td>
                                    <td><x-ui.skeleton height="var(--s9)" width="var(--d-2)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($programs->isEmpty())
                <x-ui.empty-state icon="folder"
                    :title="request()->hasAny(['q', 'state']) ? __('app.no_search_results_title') : __('admin.programs.empty_title')"
                    :description="request()->hasAny(['q', 'state']) ? __('app.no_search_results') : __('admin.programs.empty_body')"
                    :action-label="request()->hasAny(['q', 'state']) ? __('app.clear_filters') : __('admin.programs.create')"
                    :action-href="request()->hasAny(['q', 'state']) ? route('admin.programs.index') : route('admin.programs.index', ['edit' => 'new'])" />
            @else
                <div class="tscroll">
                    <table class="atable atable--stack" role="table">
                        <caption class="sr">{{ __('admin.programs.title') }}</caption>
                        <thead role="rowgroup">
                            <tr role="row">
                                <th scope="col" role="columnheader">{{ __('admin.programs.fields.name') }}</th>
                                <th scope="col" role="columnheader">{{ __('admin.programs.fields.slug') }}</th>
                                <th scope="col" role="columnheader">{{ __('admin.programs.fields.hours') }}</th>
                                <th scope="col" role="columnheader">{{ __('admin.cohorts.title') }}</th>
                                <th scope="col" role="columnheader">{{ __('admin.programs.fields.status') }}</th>
                                <th scope="col" role="columnheader"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody role="rowgroup">
                            @foreach ($programs as $program)
                                <tr role="row" @class(['is-selected' => $program->id === ($editing->id ?? null)])>
                                    <th scope="row" role="rowheader" class="atable__lead">{{ $program->name }}</th>
                                    <td role="cell" data-label="{{ __('admin.programs.fields.slug') }}"><span dir="ltr" class="u-ltr">{{ $program->slug }}</span></td>
                                    <td role="cell" data-label="{{ __('admin.programs.fields.hours') }}"><span class="u-num">{{ $program->hours }}</span></td>
                                    <td role="cell" data-label="{{ __('admin.cohorts.title') }}"><span class="u-num">{{ $program->cohortsCount }}</span></td>
                                    <td role="cell" data-label="{{ __('admin.programs.fields.status') }}">
                                        <x-ui.pill :variant="$program->statusVariant" :icon="$program->statusIcon">{{ $program->statusLabel }}</x-ui.pill>
                                    </td>
                                    <td role="cell" class="u-nowrap">
                                        <x-ui.button icon="pencil" :icon-only="true" variant="secondary" size="sm" :context="$program->name"
                                            :href="route('admin.programs.index', ['edit' => $program->id])">{{ __('app.edit') }}</x-ui.button>
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('admin.cohorts.index', ['program' => $program->id])">{{ __('admin.cohorts.title') }}</x-ui.button>
                                        @unless ($program->isArchived)
                                            <x-ui.button icon="archive" :icon-only="true" variant="secondary" size="sm" :context="$program->name"
                                                :href="route('admin.programs.index', ['archive' => $program->id])">{{ __('app.archive') }}</x-ui.button>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$programs" />
            @endif
        </x-ui.card>

        {{-- Editor ------------------------------------------------------------------ --}}
        @if ($editing)
            <x-ui.card data-open-panel tabindex="-1" class="dc--span u-mt-4" icon="folder"
                :title="$editing->exists ? __('app.edit') : __('admin.programs.create')">

                {{-- A programme is addressed by its SLUG in the route (Program::getRouteKeyName);
                     the uuid answered 404, and every typed edit was lost with it (D-147). --}}
                <form method="POST"
                    action="{{ $editing->exists ? route('admin.programs.update', $editing->slug) : route('admin.programs.store') }}">
                    @csrf
                    @if ($editing->exists)
                        @method('PATCH')
                    @endif

                    <div class="f2">
                        <x-ui.input name="name" required :label="__('admin.programs.fields.name')"
                            :value="old('name', $editing->name)" />
                        <x-ui.input name="slug" required dir="ltr"
                            :label="__('admin.programs.fields.slug')"
                            :hint="__('admin.programs.slug_hint')"
                            :value="old('slug', $editing->slug)" />
                    </div>

                    <x-ui.textarea name="summary" rows="3" required
                        :label="__('admin.programs.fields.summary')"
                        :value="old('summary', $editing->summary)" />

                    <div class="f2">
                        <x-ui.textarea name="objectives" rows="5"
                            :label="__('admin.programs.fields.objectives')"
                            :hint="__('admin.programs.one_per_line')"
                            :value="old('objectives', $editing->objectivesText)" />
                        <x-ui.textarea name="target_audience" rows="5"
                            :label="__('admin.programs.fields.target_audience')"
                            :hint="__('admin.programs.one_per_line')"
                            :value="old('target_audience', $editing->targetAudienceText)" />
                    </div>

                    <x-ui.textarea name="certificates" rows="3"
                        :label="__('admin.programs.fields.certificates')"
                        :hint="__('admin.programs.one_per_line')"
                        :value="old('certificates', $editing->certificatesText)" />

                    <div class="f2">
                        <x-ui.input name="hours" type="number" min="1" step="1" required
                            :label="__('admin.programs.fields.hours')"
                            :value="old('hours', $editing->hours)" />
                        <x-ui.select name="status" required
                            :label="__('admin.programs.fields.status')"
                            :options="$statusOptions" :value="old('status', $editing->status)" />
                    </div>

                    <p class="footnote">{{ __('admin.programs.content_note') }}</p>

                    <div class="row__acts">
                        <x-ui.button icon="check" variant="primary" type="submit">{{ __('app.save_changes') }}</x-ui.button>
                        <x-ui.button variant="ghost" :href="route('admin.programs.index')">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        {{-- Archive confirmation ------------------------------------------------------ --}}
        @if ($archiving)
            <x-ui.card data-open-panel tabindex="-1" class="dc--span u-mt-4" icon="warn" :title="__('app.archive')">
                <div class="note note--warn">
                    <b>{{ $archiving->name }}</b>
                    {{ __('admin.programs.archive_confirm') }}
                </div>

                <form method="POST" action="{{ route('admin.programs.archive', $archiving->slug) }}">
                    @csrf
                    @method('PUT')

                    <div class="row__acts">
                        <x-ui.button icon="archive" variant="danger" type="submit">{{ __('app.archive') }}</x-ui.button>
                        <x-ui.button variant="ghost" :href="route('admin.programs.index')">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif
    @endif
@endsection
