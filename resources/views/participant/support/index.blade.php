{{--
    Support — the ticket list (D-124).

    The participant sees their own tickets and where each one is, by role. The
    support team sees two tabs — the tickets waiting on them, and every ticket
    they may read — with who opened each one and in which cohort. Which
    tickets appear is SupportTicket::scopeVisibleTo, the policy's own rule.

    Four states: error · loading (the list's skeleton) · empty (copy for this
    screen, and for each tab) · normal.

    @see D-124 · BR-22 · CONSTITUTION art. 5, art. 17
--}}
@extends('layouts.app')

@section('title', __('support.title'))
@section('subtitle', $isStaff ? __('support.staff_subtitle') : __('support.subtitle'))

@section('content')
    {{-- Opening a ticket does not depend on the list: the button stays when
         the list could not be fetched. --}}
    @if ($isStaff || ($canOpen && ($rows !== [] || ($errorState ?? false))))
        <div class="toolbar">
            @if ($isStaff)
                <nav class="tkt-tabs" aria-label="{{ __('support.tabs.label') }}">
                    <a class="tkt-tabs__a" href="{{ route('support.index') }}"
                        @if ($tab === 'waiting') aria-current="page" @endif>
                        {{ __('support.tabs.waiting') }}
                        @if ($waitingCount > 0)
                            <x-ui.badge variant="count" size="sm">
                                <span class="u-num" aria-hidden="true">{{ $waitingCount }}</span>
                                <span class="ui-sr">{{ $waitingLabel }}</span>
                            </x-ui.badge>
                        @endif
                    </a>
                    <a class="tkt-tabs__a" href="{{ route('support.index', ['tab' => 'all']) }}"
                        @if ($tab === 'all') aria-current="page" @endif>
                        {{ __('support.tabs.all') }}
                    </a>
                </nav>
            @endif

            @if ($canOpen)
                <div class="toolbar__end">
                    <x-ui.button variant="primary" size="sm" icon="plus" :href="route('support.create')">{{ __('support.new') }}</x-ui.button>
                </div>
            @endif
        </div>
    @endif

    @if (! $isStaff && ! $canOpen && ! ($errorState ?? false))
        <p class="note note--info" role="note">
            <x-ui.icon name="info" />
            <span>{{ __('support.index.no_cohort', ['email' => $contactEmail]) }}</span>
        </p>
    @endif

    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('support.index.error_title')"
            :description="__('support.index.error_body')"
            :action-label="__('app.retry')" :action-href="request()->fullUrl()" />
    @elseif (is_null($rows))
        @include('partials.skeletons.support')
    @elseif ($rows === [] && $tab === 'waiting')
        <x-ui.empty-state icon="help"
            :title="__('support.index.waiting_empty_title')"
            :description="__('support.index.waiting_empty_body')"
            :action-label="__('support.index.waiting_empty_action')"
            :action-href="route('support.index', ['tab' => 'all'])" />
    @elseif ($rows === [] && $tab === 'all')
        <x-ui.empty-state icon="help"
            :title="__('support.index.all_empty_title')"
            :description="__('support.index.all_empty_body')" />
    @elseif ($rows === [])
        <x-ui.empty-state icon="help"
            :title="__('empty.support.title')"
            :description="__('empty.support.body')"
            :action-label="$canOpen ? __('empty.support.action') : null"
            :action-href="$canOpen ? route('support.create') : null" />
    @else
        <x-ui.card>
            <ul class="reslist tkt-list" role="list" aria-label="{{ __('support.index.list_label') }}">
                @foreach ($rows as $row)
                    <li class="row">
                        <div class="row__m">
                            <a href="{{ $row->href }}"><b>{{ $row->subject }}</b></a>
                            <span class="tkt-list__meta">
                                <span class="u-num" dir="ltr">{{ $row->number }}</span>
                                <span>{{ $row->category }}</span>
                                @if ($row->opener !== null)
                                    <span><span class="ui-sr">{{ __('support.index.opener') }}:</span> {{ $row->opener }}</span>
                                @endif
                                @if ($row->cohort !== null)
                                    <span><span class="ui-sr">{{ __('support.index.cohort') }}:</span> {{ $row->cohort }}</span>
                                @endif
                            </span>
                        </div>
                        <div class="row__e tkt-list__state">
                            <x-ui.pill size="sm" :variant="$row->statusVariant">{{ $row->statusLabel }}</x-ui.pill>
                            @if ($row->where !== null)
                                <span class="tkt-list__where">{{ $row->where }}</span>
                            @endif
                            <time class="tkt-list__when" datetime="{{ $row->updatedIso }}">{{ $row->updated }}</time>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        @if ($pages !== null)
            <x-ui.pagination :paginator="$pages" />
        @endif
    @endif
@endsection
