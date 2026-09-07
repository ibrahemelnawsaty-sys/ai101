{{--
    Admin · audit log.

    Read-only by construction: there is no edit control and no delete control on
    this screen, because the table itself has neither an update nor a delete path
    (Article 8, BR-27). The before/after payloads are printed through the escaping
    echo only, never through the raw one, since they hold values a user once typed.

    Every list is paginated — an audit log is the one table guaranteed to outgrow
    any page (Article 19).

    Four states: error · loading skeleton shaped like the table · empty · normal.

    @see PRD §9.18, §4.5.3 · BR-27, BR-28, BR-33, BR-34, BR-35
--}}
@extends('layouts.app')

@section('title', __('admin.audit.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.audit.index')" />
    @else

        <div class="note note--warn">
            <x-ui.icon name="lock" />
            {{ __('admin.audit.immutable_note') }}
        </div>

        <div class="toolbar u-mt-4">
            <form method="GET" action="{{ route('admin.audit.index') }}" class="toolbar__filters">
                <x-ui.search-input name="q" :value="request('q')"
                    :placeholder="__('app.search_placeholder')" />
                <x-ui.select name="actor" :label="__('admin.audit.filters.actor')"
                    :options="$actorOptions" :value="request('actor')" />
                <x-ui.select name="action" :label="__('admin.audit.filters.action')"
                    :options="$actionOptions" :value="request('action')" />
                <x-ui.select name="entity" :label="__('admin.audit.filters.entity')"
                    :options="$entityOptions" :value="request('entity')" />
                <x-ui.input name="from" type="date" dir="ltr"
                    :label="__('admin.audit.filters.range')" :value="request('from')" />
                <x-ui.input name="to" type="date" dir="ltr"
                    :label="__('app.time.to')" :value="request('to')" />
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>
            <div class="toolbar__end">
                <x-ui.button variant="secondary" size="sm"
                    :href="route('admin.audit.export', request()->query())">{{ __('app.export_excel') }}</x-ui.button>
            </div>
        </div>

        <x-ui.card class="dc--span u-mt-4" flush>
            @if (is_null($entries))
                <div class="tscroll">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 10; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-1)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-1)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-1)" /></td>
                                    <td><x-ui.skeleton height="var(--s9)" width="var(--s23)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($entries->isEmpty())
                <x-ui.empty-state icon="shield"
                    :title="__('admin.audit.empty_title')"
                    :description="__('admin.audit.empty_body')"
                    :action-label="request()->hasAny(['q', 'actor', 'action', 'entity', 'from', 'to']) ? __('app.clear_filters') : null"
                    :action-href="request()->hasAny(['q', 'actor', 'action', 'entity', 'from', 'to']) ? route('admin.audit.index') : null" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('admin.audit.title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('admin.audit.table.at') }}</th>
                                <th scope="col">{{ __('admin.audit.table.actor') }}</th>
                                <th scope="col">{{ __('admin.audit.table.action') }}</th>
                                <th scope="col">{{ __('admin.audit.table.entity') }}</th>
                                <th scope="col">{{ __('admin.audit.table.ip') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr @class(['is-selected' => $entry->id === ($opened->id ?? null)])>
                                    <td class="u-num u-nowrap">{{ \App\Support\Dates::dateTime($entry->at) }}</td>
                                    <th scope="row">
                                        <span class="cellpair">
                                            <x-ui.avatar size="sm" :name="$entry->actorName" />
                                            {{ $entry->actorName }}
                                        </span>
                                    </th>
                                    <td>{{ $entry->actionLabel }}</td>
                                    <td>
                                        {{ $entry->entityLabel }}
                                        <span class="u-muted u-ltr" dir="ltr">{{ $entry->entityId }}</span>
                                    </td>
                                    <td class="u-num u-ltr u-nowrap" dir="ltr">{{ $entry->ipAddress }}</td>
                                    <td class="u-nowrap">
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('admin.audit.index', array_merge(request()->query(), ['entry' => $entry->id]))">{{ __('app.view_details') }}</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$entries" />
            @endif
        </x-ui.card>

        {{-- One entry, before and after ------------------------------------------------ --}}
        @if ($opened)
            <x-ui.card class="dc--span u-mt-4" icon="shield" :title="$opened->actionLabel">
                <dl class="deflist">
                    <div><dt>{{ __('admin.audit.table.at') }}</dt><dd class="u-num">{{ \App\Support\Dates::dateTime($opened->at) }}</dd></div>
                    <div><dt>{{ __('admin.audit.table.actor') }}</dt><dd>{{ $opened->actorName }}</dd></div>
                    <div><dt>{{ __('admin.audit.table.entity') }}</dt><dd dir="ltr">{{ $opened->entityLabel }} · {{ $opened->entityId }}</dd></div>
                    <div><dt>{{ __('admin.audit.table.ip') }}</dt><dd dir="ltr" class="u-num">{{ $opened->ipAddress }}</dd></div>
                    <div><dt>{{ __('admin.audit.table.user_agent') }}</dt><dd dir="ltr">{{ $opened->userAgent }}</dd></div>
                </dl>

                <div class="f2">
                    <div>
                        <h3 class="abrief__sub">{{ __('admin.audit.table.before') }}</h3>
                        @if ($opened->beforeText === null)
                            <p class="u-muted">{{ __('app.none') }}</p>
                        @else
                            <pre class="codebox" dir="ltr">{{ $opened->beforeText }}</pre>
                        @endif
                    </div>
                    <div>
                        <h3 class="abrief__sub">{{ __('admin.audit.table.after') }}</h3>
                        @if ($opened->afterText === null)
                            <p class="u-muted">{{ __('app.none') }}</p>
                        @else
                            <pre class="codebox" dir="ltr">{{ $opened->afterText }}</pre>
                        @endif
                    </div>
                </div>

                <div class="row__acts">
                    <x-ui.button variant="ghost"
                        :href="route('admin.audit.index', request()->except('entry'))">{{ __('app.close') }}</x-ui.button>
                </div>

                <p class="footnote">{{ __('admin.audit.immutable_note') }}</p>
            </x-ui.card>
        @endif
    @endif
@endsection
