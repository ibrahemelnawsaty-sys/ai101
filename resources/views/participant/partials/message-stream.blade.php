{{--
    The message list of one conversation.

    Rendered by the messages page AND by the poll endpoint, so a live update is
    the same markup as a reload — one source of presentation, and user content
    escaped by Blade rather than assembled in JavaScript (PRD §9.13.2, D-67).

    Variables: $activeThread  App\Presenters\Participant\ActiveThreadPresenter

    @see PRD §9.13, §9.13.2 · BR-22 · D-67
--}}
@if ($activeThread->messages->isEmpty())
    <x-ui.empty-state icon="chat" size="sm"
        :title="__('messages.thread_empty_title')"
        :description="__('messages.thread_empty_body')" />
@else
    @foreach ($activeThread->messages as $message)
        <div class="msg {{ $message->isMine ? 'msg--out' : 'msg--in' }}">
            @unless ($message->isMine)
                <b class="msg__who">{{ $message->authorName }}</b>
            @endunless

            {{-- Escaped output: user content is never rendered as raw HTML. --}}
            <p class="msg__body">{{ $message->body }}</p>

            @if ($message->attachments->isNotEmpty())
                <ul class="filelist filelist--inline">
                    @foreach ($message->attachments as $attachment)
                        <li>
                            <a href="{{ $attachment->downloadUrl }}">
                                <x-ui.icon name="file" />{{ $attachment->name }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            <time class="u-num" datetime="{{ \App\Support\Dates::isoUtc($message->sentAt) }}">
                {{ \App\Support\Dates::time12($message->sentAt) }}
            </time>

            @if ($message->isMine && $activeThread->type === 'trainer_dm')
                <span class="msg__read">{{ $message->isRead ? __('messages.read') : __('messages.sent') }}</span>
            @endif

            @if ($message->canEdit)
                <div class="msg__acts">
                    <x-ui.button variant="ghost" size="sm"
                        :href="route('messages.edit', $message->id)">{{ __('app.edit') }}</x-ui.button>
                </div>
            @endif
        </div>
    @endforeach
@endif
