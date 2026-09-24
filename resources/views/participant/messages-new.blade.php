{{--
    Messages · start a conversation (D-118).

    The list is ConversationRules::recipients — exactly the people this account
    may start a conversation with — and the start endpoint asks the same rule
    again, so the list is a courtesy and the server is the guard (art. 5). A
    general supervisor also sees the system administrators' shared inbox.

    One page of people at a time, searchable by name or address. Choosing
    someone you already talk to opens that conversation; nothing is duplicated.

    Four states: error · loading (the list's skeleton) · empty (nobody to write
    to, or nobody matching the search) · normal.

    @see PRD §9.13 · BR-22 · D-118
--}}
@extends('layouts.app')

@section('title', __('messages.start.title'))
@section('subtitle', __('messages.start.subtitle'))

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('messages.error_title')"
            :description="__('messages.error_body')"
            :action-label="__('app.retry')" :action-href="route('messages.create')" />
    @elseif (is_null($recipients))
        <x-ui.card class="dc--span">
            @for ($i = 0; $i < 5; $i++)
                <x-ui.skeleton height="var(--touch-min)" width="100%" class="u-mt-2" />
            @endfor
        </x-ui.card>
    @else
        <x-ui.card class="dc--span" icon="chat" :title="__('messages.start.title')">
            <x-slot:action>
                <a href="{{ route('messages.index') }}">{{ __('messages.start.back') }}</a>
            </x-slot:action>

            <form method="GET" action="{{ route('messages.create') }}" class="toolbar">
                <x-ui.search-input name="q" :value="$search" :placeholder="__('messages.start.search_label')" />
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('messages.start.search_submit') }}</x-ui.button>
            </form>

            @if ($options === [])
                <x-ui.empty-state icon="users"
                    :title="$search === '' ? __('messages.start.empty_title') : __('messages.start.no_match_title')"
                    :description="$search === '' ? __('messages.start.empty_body') : __('messages.start.no_match_body')"
                    :action-label="$search === '' ? __('messages.start.back') : __('app.clear_filters')"
                    :action-href="$search === '' ? route('messages.index') : route('messages.create')" />
            @else
                <form method="POST" action="{{ route('messages.start') }}" class="u-mt-4">
                    @csrf

                    <x-ui.radio name="recipient" required variant="cards"
                        :legend="__('messages.start.recipient_legend')"
                        :options="$options" />

                    <x-ui.textarea name="body" rows="3" required maxlength="5000"
                        :label="__('messages.start.body_label')"
                        :hint="__('messages.start.existing_note')"
                        :value="old('body')" />

                    <div class="row__acts">
                        <x-ui.button variant="primary" type="submit">{{ __('messages.start.submit') }}</x-ui.button>
                    </div>
                </form>

                <x-ui.pagination :paginator="$recipients" />
            @endif
        </x-ui.card>
    @endif
@endsection
