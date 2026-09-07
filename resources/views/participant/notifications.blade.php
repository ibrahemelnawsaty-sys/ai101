{{--
    Notification centre. In preview mode nothing is marked as read — the
    "mark all as read" form is not rendered and the endpoint rejects the call (BR-34).

    @see PRD §9.16 · BR-34
--}}
@extends('layouts.app')

@section('title', __('nav.notifications'))
@section('subtitle', __('notifications.subtitle'))

@section('content')
    <div class="toolbar">
        <form method="GET" action="{{ route('notifications') }}" class="toolbar__filters">
            <x-ui.select name="type" :label="__('notifications.filter_type')" :options="$typeOptions" :value="request('type')" />
            <x-ui.select name="state" :label="__('notifications.filter_state')" :options="$stateOptions" :value="request('state')" />
            <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
        </form>

        @if (! $isImpersonating && $unreadCount > 0)
            <form method="POST" action="{{ route('notifications.readAll') }}" class="toolbar__end">
                @csrf
                @method('PUT')
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('notifications.mark_all_read') }}</x-ui.button>
            </form>
        @endif
    </div>

    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('notifications.error_title')"
            :description="__('notifications.error_body')"
            :action-label="__('app.retry')" :action-href="route('notifications')" />
    @elseif (is_null($notifications))
        <x-ui.card>
            @for ($i = 0; $i < 6; $i++)
                <div class="row row--sk">
                    <x-ui.skeleton height="var(--s8)" width="var(--s8)" rounded="md" />
                    <div class="row__m">
                        <x-ui.skeleton height="var(--s4)" width="62%" />
                        <x-ui.skeleton height="var(--s3)" width="44%" class="u-mt-1" />
                    </div>
                    <x-ui.skeleton height="var(--s3)" width="var(--s15)" />
                </div>
            @endfor
        </x-ui.card>
    @elseif ($notifications->isEmpty())
        <x-ui.empty-state icon="bell"
            :title="request()->hasAny(['type', 'state']) ? __('notifications.no_match_title') : __('notifications.empty_title')"
            :description="request()->hasAny(['type', 'state']) ? __('notifications.no_match_body') : __('notifications.empty_body')"
            :action-label="request()->hasAny(['type', 'state']) ? __('app.clear_filters') : null"
            :action-href="request()->hasAny(['type', 'state']) ? route('notifications') : null" />
    @else
        <x-ui.card>
            <ul class="notiflist" role="list">
                @foreach ($notifications as $notification)
                    <li class="row {{ $notification->isRead ? '' : 'is-unread' }}">
                        <span class="notif__ic notif__ic--{{ $notification->type }}" aria-hidden="true">
                            <x-ui.icon :name="$notification->icon" />
                        </span>
                        <div class="row__m">
                            <a href="{{ $notification->targetUrl }}">
                                <b>{{ $notification->title }}</b>
                            </a>
                            <span>{{ $notification->body }}</span>
                        </div>
                        <div class="row__e">
                            <time class="u-num" datetime="{{ \App\Support\Dates::isoUtc($notification->createdAt) }}">
                                {{ \App\Support\Dates::relative($notification->createdAt) }}
                            </time>
                            @unless ($notification->isRead)
                                <span class="notif__dot" aria-label="{{ __('notifications.unread') }}"></span>
                            @endunless
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <x-ui.pagination :paginator="$notifications" />
    @endif
@endsection
