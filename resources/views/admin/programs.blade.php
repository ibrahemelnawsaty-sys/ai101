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
                <x-ui.select name="status" :label="__('admin.programs.fields.status')"
                    :options="$statusOptions" :value="request('status')" />
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>
            <div class="toolbar__end">
                <x-ui.button variant="primary" size="sm"
                    :href="route('admin.programs.index', ['edit' => 'new'])">{{ __('admin.programs.create') }}</x-ui.button>
            </div>
        </div>

        <x-ui.card class="dc--span u-mt-4" flush>
            @if (is_null($programs))
                <div class="tscroll">
                    <table class="atable">
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
                    :title="request()->hasAny(['q', 'status']) ? __('app.no_search_results_title') : __('admin.programs.empty_title')"
                    :description="request()->hasAny(['q', 'status']) ? __('app.no_search_results') : __('admin.programs.empty_body')"
                    :action-label="request()->hasAny(['q', 'status']) ? __('app.clear_filters') : __('admin.programs.create')"
                    :action-href="request()->hasAny(['q', 'status']) ? route('admin.programs.index') : route('admin.programs.index', ['edit' => 'new'])" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('admin.programs.title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('admin.programs.fields.name') }}</th>
                                <th scope="col">{{ __('admin.programs.fields.slug') }}</th>
                                <th scope="col">{{ __('admin.programs.fields.hours') }}</th>
                                <th scope="col">{{ __('admin.cohorts.title') }}</th>
                                <th scope="col">{{ __('admin.programs.fields.status') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($programs as $program)
                                <tr @class(['is-selected' => $program->id === ($editing->id ?? null)])>
                                    <th scope="row">{{ $program->name }}</th>
                                    <td dir="ltr" class="u-ltr">{{ $program->slug }}</td>
                                    <td class="u-num">{{ $program->hours }}</td>
                                    <td class="u-num">{{ $program->cohortsCount }}</td>
                                    <td>
                                        <x-ui.pill :variant="$program->statusVariant" :icon="$program->statusIcon">{{ $program->statusLabel }}</x-ui.pill>
                                    </td>
                                    <td class="u-nowrap">
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('admin.programs.index', ['edit' => $program->id])">{{ __('app.edit') }}</x-ui.button>
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('admin.cohorts.index', ['program' => $program->id])">{{ __('admin.cohorts.title') }}</x-ui.button>
                                        @unless ($program->isArchived)
                                            <x-ui.button variant="secondary" size="sm"
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
            <x-ui.card class="dc--span u-mt-4" icon="folder"
                :title="$editing->exists ? __('app.edit') : __('admin.programs.create')">

                <form method="POST"
                    action="{{ $editing->exists ? route('admin.programs.update', $editing->id) : route('admin.programs.store') }}">
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
                        <x-ui.button variant="primary" type="submit">{{ __('app.save_changes') }}</x-ui.button>
                        <x-ui.button variant="ghost" :href="route('admin.programs.index')">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        {{-- Archive confirmation ------------------------------------------------------ --}}
        @if ($archiving)
            <x-ui.card class="dc--span u-mt-4" icon="warn" :title="__('app.archive')">
                <div class="note note--warn">
                    <b>{{ $archiving->name }}</b>
                    {{ __('admin.programs.archive_confirm') }}
                </div>

                <form method="POST" action="{{ route('admin.programs.archive', $archiving->id) }}">
                    @csrf
                    @method('PUT')

                    <div class="row__acts">
                        <x-ui.button variant="danger" type="submit">{{ __('app.archive') }}</x-ui.button>
                        <x-ui.button variant="ghost" :href="route('admin.programs.index')">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif
    @endif
@endsection
