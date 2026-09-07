{{--
    Training kit — resources grouped by week plus a "general" section.
    Downloads go through a temporary signed URL (15 minutes) issued only after the
    policy check; the storage path is never exposed.

    @see PRD §9.12 · BR-22
--}}
@extends('layouts.app')

@section('title', __('nav.resources'))
@section('subtitle', __('resources.subtitle'))

@section('content')
    <form method="GET" action="{{ route('resources.index') }}" class="toolbar">
        <x-ui.search-input name="q" :value="request('q')" :placeholder="__('resources.search_placeholder')" />
        <x-ui.select name="type" :label="__('resources.filter_type')" :options="$typeOptions" :value="request('type')" />
        <x-ui.select name="week" :label="__('schedule.filter_week')" :options="$weekOptions" :value="request('week')" />
        <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
    </form>

    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('resources.error_title')"
            :description="__('resources.error_body')"
            :action-label="__('app.retry')" :action-href="route('resources.index')" />
    @elseif (is_null($groups))
        <div class="reslist">
            @for ($i = 0; $i < 6; $i++)
                <x-ui.card>
                    <x-ui.skeleton height="var(--s9)" width="var(--s9)" rounded="md" />
                    <x-ui.skeleton height="var(--s4)" width="70%" class="u-mt-2" />
                    <x-ui.skeleton height="var(--s3)" width="90%" class="u-mt-1" />
                    <x-ui.skeleton height="var(--s3)" width="40%" class="u-mt-1" />
                </x-ui.card>
            @endfor
        </div>
    @elseif ($groups->isEmpty())
        <x-ui.empty-state icon="folder"
            :title="request('q') ? __('resources.no_match_title') : __('resources.empty_title')"
            :description="request('q') ? __('resources.no_match_body') : __('resources.empty_body')"
            :action-label="request('q') ? __('app.clear_filters') : null"
            :action-href="request('q') ? route('resources.index') : null" />
    @else
        @foreach ($groups as $group)
            <section class="resgroup">
                <h2 class="resgroup__t">{{ $group->title }}</h2>

                @if ($group->items->isEmpty())
                    <x-ui.empty-state icon="folder" size="sm"
                        :title="__('resources.group_empty_title')"
                        :description="__('resources.group_empty_body')" />
                @else
                    <div class="reslist">
                        @foreach ($group->items as $resource)
                            <x-ui.card class="res {{ $resource->id === request('highlight') ? 'is-highlight' : '' }}">
                                <div class="res__hd">
                                    <span class="res__ic res__ic--{{ $resource->type }}" aria-hidden="true">
                                        <x-ui.icon :name="$resource->typeIcon" />
                                    </span>
                                    @if ($resource->isNew)
                                        <x-ui.pill variant="primary">{{ __('resources.new_badge') }}</x-ui.pill>
                                    @endif
                                </div>
                                <b class="res__t">{{ $resource->title }}</b>
                                <p class="res__d">{{ $resource->description }}</p>
                                <div class="res__meta">
                                    <span>{{ $resource->typeLabel }}</span>
                                    @if ($resource->sizeLabel)
                                        · <span class="u-num">{{ $resource->sizeLabel }}</span>
                                    @endif
                                    · <span class="u-num">{{ \App\Support\Dates::shortDate($resource->addedAt) }}</span>
                                </div>
                                <div class="res__acts">
                                    @if ($resource->isPreviewable)
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('resources.preview', $resource->id)">{{ __('resources.preview') }}</x-ui.button>
                                    @endif
                                    <x-ui.button variant="primary" size="sm" icon="down"
                                        :href="route('resources.download', $resource->id)">{{ __('resources.download') }}</x-ui.button>
                                </div>
                            </x-ui.card>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach

        <x-ui.pagination :paginator="$paginator" />
    @endif
@endsection
