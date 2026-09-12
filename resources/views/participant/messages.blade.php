{{--
    Internal messaging — trainer DM, cohort group, announcements channel.
    Announcements are read-only for participants; the composer is not rendered for them,
    and the send endpoint enforces the same rule.

    In preview (impersonation) mode nothing is marked as read — the read receipt is
    written by the server only when $isImpersonating is false (BR-34).

    @see PRD §9.13 · BR-34
--}}
@extends('layouts.app')

@section('title', __('nav.messages'))
@section('subtitle', __('messages.subtitle'))

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('messages.error_title')"
            :description="__('messages.error_body')"
            :action-label="__('app.retry')" :action-href="route('messages.index')" />
    @elseif (is_null($threads))
        <div class="chat">
            <div class="chat__main">
                <div class="chat__hd"><x-ui.skeleton height="var(--s8)" width="var(--s8)" rounded="full" /><x-ui.skeleton height="var(--s4)" width="var(--d-2)" /></div>
                <div class="chat__msgs">
                    @for ($i = 0; $i < 5; $i++)
                        <x-ui.skeleton height="var(--touch-min)" width="{{ $i % 2 ? '52%' : '64%' }}" rounded="lg" class="u-mt-2" />
                    @endfor
                </div>
            </div>
            <div class="chat__list">
                @for ($i = 0; $i < 3; $i++)
                    <div class="chat__ci"><x-ui.skeleton height="var(--s8)" width="var(--s8)" rounded="full" /><x-ui.skeleton height="var(--s3)" width="60%" /></div>
                @endfor
            </div>
        </div>
    @elseif ($threads->isEmpty())
        <x-ui.empty-state icon="chat"
            :title="__('messages.empty_title')"
            :description="__('messages.empty_body')" />
    @else
        <div class="chat"
            x-data="atharThread({
                pollUrl: '{{ route('messages.poll', $activeThread->id) }}',
                pollSeconds: @js($pollSeconds),
                readOnly: @js($isImpersonating)
            })">

            <div class="chat__main">
                <div class="chat__hd">
                    <x-ui.avatar size="sm" :name="$activeThread->title" :variant="$activeThread->avatarVariant" />
                    <div>
                        <b>{{ $activeThread->title }}</b>
                        <span>{{ $activeThread->subtitle }}</span>
                    </div>
                    @if ($activeThread->isLocked)
                        <x-ui.pill variant="warning" icon="lock">{{ __('messages.locked') }}</x-ui.pill>
                    @endif
                </div>

                <div class="chat__msgs" x-ref="stream" aria-live="polite" aria-atomic="false"
                    data-latest="{{ $activeThread->latestMessageId }}">
                    @include('participant.partials.message-stream', ['activeThread' => $activeThread])
                </div>

                @if ($activeThread->canPost)
                    <form method="POST" action="{{ route('messages.store', $activeThread->id) }}"
                        enctype="multipart/form-data" class="chat__in">
                        @csrf
                        <x-ui.input name="body" :label="__('messages.compose_label')" label-hidden
                            :placeholder="__('messages.compose_placeholder')" required />
                        <x-ui.button variant="ghost" size="sm" type="button" icon="up"
                            :aria-label="__('messages.attach')"
                            x-on:click="$refs.attach.click()" />
                        <input type="file" name="attachments[]" multiple class="sr" x-ref="attach"
                            aria-label="{{ __('messages.attach') }}">
                        <x-ui.button variant="primary" size="sm" type="submit">{{ __('messages.send') }}</x-ui.button>
                    </form>
                @else
                    <p class="chat__readonly" role="status">
                        <x-ui.icon name="lock" />
                        {{ $activeThread->readOnlyReason }}
                    </p>
                @endif
            </div>

            <nav class="chat__list" aria-label="{{ __('messages.threads') }}">
                @foreach ($threads as $thread)
                    <a class="chat__ci {{ $thread->id === $activeThread->id ? 'on' : '' }}"
                        href="{{ route('messages.index', ['thread' => $thread->id]) }}"
                        @if ($thread->id === $activeThread->id) aria-current="page" @endif>
                        <x-ui.avatar size="sm" :name="$thread->title" :variant="$thread->avatarVariant" :icon="$thread->icon" />
                        <div class="chat__ct">
                            <b>{{ $thread->title }}</b>
                            <span>{{ $thread->previewLine }}</span>
                        </div>
                        @if ($thread->unreadCount > 0)
                            <x-ui.badge :count="$thread->unreadCount"
                                :aria-label="trans_choice('messages.unread_count', $thread->unreadCount, ['count' => $thread->unreadCount])" />
                        @endif
                    </a>
                @endforeach
            </nav>
        </div>
    @endif
@endsection
