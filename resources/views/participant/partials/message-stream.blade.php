{{--
    The message list of one conversation.

    Rendered by the messages page AND by the poll endpoint, so a live update is
    the same markup as a reload — one source of presentation, and user content
    escaped by Blade rather than assembled in JavaScript (PRD §9.13.2, D-67).

    Variables: $activeThread  App\Presenters\Participant\ActiveThreadPresenter

    The edit control is a small PATCH form (D-118). It used to be a link — a
    GET to a route that accepts PATCH only — so editing never worked.

    @see PRD §9.13, §9.13.2 · BR-22 · D-67, D-118
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

            @if ($message->isMine && $activeThread->isOneToOne)
                <span class="msg__read">{{ $message->isRead ? __('messages.read') : __('messages.sent') }}</span>
            @endif

            @if ($message->canEdit)
                <details class="msg__edit">
                    <summary>{{ __('app.edit') }}</summary>
                    <form method="POST" action="{{ route('messages.edit', $message->id) }}">
                        @csrf
                        @method('PATCH')
                        <x-ui.textarea name="body" rows="2" required maxlength="5000"
                            :id="'msg-edit-' . $message->id"
                            :label="__('messages.compose_label')"
                            :hint="__('messages.edit_hint')"
                            :value="$message->body" />
                        <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.save_changes') }}</x-ui.button>
                    </form>
                </details>
            @endif
        </div>
    @endforeach
@endif
