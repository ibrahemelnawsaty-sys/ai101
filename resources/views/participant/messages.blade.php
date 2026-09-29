{{--
    Internal messaging — trainer DM, cohort group, announcements channel.
    Announcements are read-only for participants; the composer is not rendered for them,
    and the send endpoint enforces the same rule.

    In preview (impersonation) mode nothing is marked as read — the read receipt is
    written by the server only when $isImpersonating is false (BR-34).

    D-118 — «New conversation» opens the picker of the people this account may
    write to; it is not offered in a preview, and its routes refuse one anyway.

    @see PRD §9.13 · BR-34 · D-118
--}}
@extends('layouts.app')

@section('title', __('nav.messages'))
@section('subtitle', __('messages.subtitle'))

@section('content')
    @if (($canStart ?? false) && ! ($errorState ?? false) && ! is_null($threads) && $threads->isNotEmpty())
        <div class="toolbar">
            <div class="toolbar__end">
                <x-ui.button variant="primary" size="sm" icon="plus" :href="route('messages.create')">{{ __('messages.new_conversation') }}</x-ui.button>
            </div>
        </div>
    @endif

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
            :description="__('messages.empty_body')"
            :action-label="($canStart ?? false) ? __('messages.new_conversation') : null"
            :action-href="($canStart ?? false) ? route('messages.create') : null" />
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
                    {{-- D-136: a message may be a file alone, so the words are not
                         `required` here — the server refuses a message with neither
                         (SendMessageRequest), and the button waits for one of them. --}}
                    <form method="POST" action="{{ route('messages.store', $activeThread->id) }}"
                        enctype="multipart/form-data" class="chat__in"
                        x-data="{ files: [], body: @js(old('body', ''), JSON_UNESCAPED_UNICODE) }">
                        @csrf
                        <x-ui.input name="body" :label="__('messages.compose_label')" label-hidden
                            :placeholder="__('messages.compose_placeholder')" :value="old('body')" x-model="body" />
                        <x-ui.button variant="ghost" size="sm" type="button" icon="attach"
                            :aria-label="__('messages.attach')"
                            x-on:click="$refs.attach.click()" />
                        <input type="file" name="attachments[]" multiple class="sr" x-ref="attach"
                            aria-label="{{ __('messages.attach') }}"
                            x-on:change="files = Array.from($event.target.files).map((file) => file.name)">
                        <x-ui.button icon="send" variant="primary" size="sm" type="submit"
                            x-bind:disabled="body.trim() === '' && files.length === 0">{{ __('messages.send') }}</x-ui.button>

                        <p class="chat__note">{{ trans_choice('messages.attach_limits', $attachmentMaxFiles, ['count' => $attachmentMaxFiles, 'size' => $attachmentMaxMegabytes]) }}</p>

                        <div class="chat__files" x-show="files.length > 0" x-cloak>
                            <span>{{ __('messages.attach_selected') }}</span>
                            <ul class="filelist filelist--inline">
                                <template x-for="name in files" :key="name">
                                    <li><x-ui.icon name="file" /><span dir="auto" x-text="name"></span></li>
                                </template>
                            </ul>
                            <x-ui.button variant="ghost" size="sm" type="button" icon="x"
                                x-on:click="$refs.attach.value = ''; files = []">{{ __('messages.attach_clear') }}</x-ui.button>
                        </div>

                        @error('attachments')
                            <p class="hint hint--bad chat__err" role="alert"><x-ui.icon name="warn" /><span>{{ $message }}</span></p>
                        @enderror
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
                    <a class="chat__ci {{ $thread->id === $activeThread->id ? 'is-on' : '' }}"
                        href="{{ route('messages.index', ['thread' => $thread->id]) }}"
                        @if ($thread->id === $activeThread->id) aria-current="page" @endif>
                        <x-ui.avatar size="sm" :name="$thread->title" :variant="$thread->avatarVariant" :icon="$thread->icon" />
                        <div class="chat__ct">
                            <b>{{ $thread->title }}</b>
                            <span>{{ $thread->previewLine }}</span>
                        </div>
                        @if ($thread->unreadCount > 0)
                            <x-ui.badge variant="count" size="sm">
                                <span class="u-num" aria-hidden="true">{{ $thread->unreadCount }}</span>
                                <span class="ui-sr">{{ trans_choice('messages.unread_count', $thread->unreadCount, ['count' => $thread->unreadCount]) }}</span>
                            </x-ui.badge>
                        @endif
                    </a>
                @endforeach

                @isset ($threadPages)
                    <x-ui.pagination :paginator="$threadPages" />
                @endisset
            </nav>
        </div>
    @endif
@endsection
