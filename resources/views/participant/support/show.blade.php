{{--
    Support — one ticket (D-124).

    Its details, its timeline and what the reader may do: the participant
    answers or closes; the support team writes, resolves and moves it between
    levels. Every flag below comes from TicketPage, which asks the policy —
    and every form's route asks it again (art. 5). The participant's timeline
    never holds an internal line: the controller does not load one.

    Pictures and videos show inside the page through signed links that last
    fifteen minutes; a video asks for ranges, so it plays and seeks without
    downloading first.

    @see D-124 · BR-22, BR-33 · CONSTITUTION art. 5, art. 17, art. 18, art. 24
--}}
@extends('layouts.app')

@section('title', $ticket->subject)
@section('subtitle', __('support.title'))

@section('content')
    {{-- A refusal TicketWorkflow raised after the page was drawn: the ticket
         closed, or someone else moved it first (SupportTicketException). --}}
    @error('message')
        <p class="note note--bad" role="alert">
            <x-ui.icon name="warn" />
            <span>{{ $message }}</span>
        </p>
    @enderror

    <x-ui.card class="dc--span" icon="help" :title="$ticket->subject">
        <x-slot:action>
            <a href="{{ route('support.index') }}">{{ __('support.show.back') }}</a>
        </x-slot:action>

        <dl class="deflist tkt-meta" aria-label="{{ __('support.show.meta_label') }}">
            <div>
                <dt>{{ __('support.show.number') }}</dt>
                <dd class="u-num" dir="ltr">{{ $ticket->number }}</dd>
            </div>
            <div>
                <dt>{{ __('support.show.status') }}</dt>
                <dd><x-ui.pill size="sm" :variant="$ticket->statusVariant">{{ $ticket->statusLabel }}</x-ui.pill></dd>
            </div>
            @if ($ticket->where !== null)
                <div>
                    <dt>{{ __('support.show.where') }}</dt>
                    <dd>{{ $ticket->where }}</dd>
                </div>
            @endif
            @if ($ticket->holder !== null)
                <div>
                    <dt>{{ __('support.show.holder') }}</dt>
                    <dd>{{ $ticket->holder }}</dd>
                </div>
            @endif
            <div>
                <dt>{{ __('support.show.category') }}</dt>
                <dd>{{ $ticket->category }}</dd>
            </div>
            @if ($ticket->opener !== null)
                <div>
                    <dt>{{ __('support.show.opener') }}</dt>
                    <dd>{{ $ticket->opener }}</dd>
                </div>
            @endif
            @if ($ticket->cohort !== null)
                <div>
                    <dt>{{ __('support.show.cohort') }}</dt>
                    <dd>{{ $ticket->cohort }}</dd>
                </div>
            @endif
            <div>
                <dt>{{ __('support.show.opened') }}</dt>
                <dd>{{ $ticket->openedAt }}</dd>
            </div>
        </dl>

        @if ($ticket->resolvedNote !== null)
            <p class="note note--ok u-mt-4" role="status">
                <x-ui.icon name="check" />
                <span>{{ $ticket->resolvedNote }}</span>
            </p>
        @endif

        @if ($ticket->closedNote !== null)
            <p class="note note--info u-mt-4" role="status">
                <x-ui.icon name="lock" />
                <span>{{ $ticket->closedNote }}</span>
            </p>
        @endif

        @if ($ticket->followOnly)
            <p class="note note--info u-mt-4" role="note">
                <x-ui.icon name="eye" />
                <span>{{ __('support.actions.follow_only') }}</span>
            </p>
        @endif
    </x-ui.card>

    {{-- The timeline ---------------------------------------------------------- --}}
    <x-ui.card class="dc--span u-mt-4" icon="route" :title="__('support.show.timeline')">
        <ol class="tkt-log">
            @foreach ($ticket->entries as $entry)
                <li @class([
                    'tkt-log__i',
                    'tkt-log__i--own' => $entry->isParticipant,
                    'tkt-log__i--internal' => $entry->isInternal,
                ])>
                    <span class="tkt-log__ic" aria-hidden="true"><x-ui.icon :name="$entry->icon" size="sm" /></span>
                    <div class="tkt-log__b">
                        <p class="tkt-log__hd">
                            <b>{{ $entry->headline }}</b>
                            @if ($entry->isInternal)
                                <x-ui.pill size="sm" variant="neutral" icon="lock">{{ __('support.show.internal') }}</x-ui.pill>
                                <span class="sr">{{ __('support.show.internal_hint') }}</span>
                            @endif
                            <time class="u-num tkt-log__when" datetime="{{ $entry->iso }}">{{ $entry->when }}</time>
                        </p>

                        @if ($entry->body !== null)
                            <p class="tkt-log__body">{{ $entry->body }}</p>
                        @endif

                        @if ($entry->link !== null)
                            <p class="tkt-log__link">
                                <x-ui.icon name="external" size="sm" />
                                <span class="sr">{{ __('support.show.link') }}</span>
                                <a href="{{ $entry->link }}" dir="ltr" target="_blank" rel="noopener noreferrer nofollow">{{ $entry->link }}</a>
                            </p>
                        @endif

                        @if ($entry->files !== [])
                            <ul class="tkt-files" role="list" aria-label="{{ __('support.show.attachments') }}">
                                @foreach ($entry->files as $file)
                                    <li class="tkt-files__i">
                                        @if ($file->isVideo)
                                            <video class="tkt-files__media" controls preload="metadata"
                                                src="{{ $file->url }}" aria-label="{{ $file->videoLabel }}"></video>
                                        @else
                                            <a href="{{ $file->url }}" target="_blank" rel="noopener">
                                                <img class="tkt-files__media" src="{{ $file->url }}" alt="{{ $file->name }}" loading="lazy">
                                            </a>
                                        @endif
                                        <a class="tkt-files__name" href="{{ $file->url }}" target="_blank" rel="noopener">{{ $file->openLabel }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </x-ui.card>

    {{-- The participant --------------------------------------------------------- --}}
    @if ($ticket->canReply)
        <x-ui.card class="dc--span u-mt-4" icon="chat" :title="__('support.reply.title')">
            @if ($ticket->replyReopens)
                <p class="note note--info" role="note">
                    <x-ui.icon name="route" />
                    <span>{{ __('support.reply.reopens') }}</span>
                </p>
            @endif

            <form method="POST" action="{{ route('support.reply', $ticket->id) }}" enctype="multipart/form-data" class="form">
                @csrf

                <x-ui.textarea name="body" rows="4" required :maxlength="$bodyMax"
                    :label="__('support.reply.body')"
                    :value="old('body')" />

                <x-ui.input name="link" type="url" ltr
                    :label="__('support.create.link')"
                    :hint="__('support.create.link_hint')"
                    :value="old('link')" />

                <x-ui.file-uploader name="attachments" multiple
                    :max-files="$maxFiles" :max-mb="$maxMb" :accept="$accept"
                    :label="__('support.create.files')"
                    :hint="$filesHint" />

                @include('participant.support.partials.attachment-errors')

                <div class="form__submit">
                    <x-ui.button variant="primary" type="submit" icon="send">{{ __('support.reply.submit') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if ($ticket->canClose)
        <x-ui.card class="dc--span u-mt-4" icon="lock" :title="__('support.close.title')">
            <p>{{ __('support.close.hint') }}</p>
            <form method="POST" action="{{ route('support.close', $ticket->id) }}" class="u-mt-2">
                @csrf
                <x-ui.button variant="secondary" type="submit">{{ __('support.close.submit') }}</x-ui.button>
            </form>
        </x-ui.card>
    @endif

    {{-- The support team -------------------------------------------------------- --}}
    @if ($ticket->canNote || $ticket->canResolve || $ticket->canEscalate || $ticket->canReturn || $ticket->canAssign)
        <x-ui.card class="dc--span u-mt-4" icon="cog" :title="__('support.actions.title')">
            <div class="tkt-acts">
                @if ($ticket->canNote)
                    <form method="POST" action="{{ route('support.note', $ticket->id) }}" enctype="multipart/form-data" class="form tkt-act">
                        @csrf

                        <x-ui.textarea name="body" id="note-body" rows="4" required :maxlength="$bodyMax"
                            :label="__('support.actions.compose')"
                            :value="old('body')" />

                        @if ($ticket->canWriteToParticipant)
                            <x-ui.checkbox name="internal" value="1"
                                :label="__('support.actions.internal')"
                                :description="__('support.actions.message_hint')"
                                :checked="(bool) old('internal')" />
                        @else
                            <p class="note note--info" role="note">
                                <x-ui.icon name="lock" />
                                <span>{{ $ticket->internalOnlyNote }}</span>
                            </p>
                        @endif

                        <x-ui.input name="link" id="note-link" type="url" ltr
                            :label="__('support.create.link')"
                            :hint="__('support.create.link_hint')"
                            :value="old('link')" />

                        <x-ui.file-uploader name="attachments" id="note-files" multiple
                            :max-files="$maxFiles" :max-mb="$maxMb" :accept="$accept"
                            :label="__('support.create.files')"
                            :hint="$filesHint" />

                        @include('participant.support.partials.attachment-errors')

                        <div class="form__submit">
                            <x-ui.button variant="primary" type="submit">{{ __('support.actions.compose_submit') }}</x-ui.button>
                        </div>
                    </form>
                @endif

                @if ($ticket->canResolve)
                    <form method="POST" action="{{ route('support.resolve', $ticket->id) }}" class="form tkt-act">
                        @csrf

                        <x-ui.textarea name="summary" rows="3" :maxlength="$bodyMax"
                            :label="__('support.actions.resolve_body')"
                            :hint="__('support.actions.resolve_hint', ['hours' => $ticket->hoursText])"
                            :value="old('summary')" />

                        <div class="form__submit">
                            <x-ui.button variant="primary" type="submit" icon="check">{{ __('support.actions.resolve') }}</x-ui.button>
                        </div>
                    </form>
                @endif

                @if ($ticket->canEscalate)
                    <form method="POST" action="{{ route('support.escalate', $ticket->id) }}" class="form tkt-act">
                        @csrf

                        <x-ui.textarea name="escalate_note" rows="2" :maxlength="$bodyMax"
                            :label="__('support.actions.escalate_note')"
                            :hint="__('support.actions.escalate_hint', ['to' => $ticket->escalateTo])"
                            :value="old('escalate_note')" />

                        <div class="form__submit">
                            <x-ui.button variant="secondary" type="submit" icon="up">{{ __('support.actions.escalate', ['to' => $ticket->escalateTo]) }}</x-ui.button>
                        </div>
                    </form>
                @endif

                @if ($ticket->canReturn)
                    <form method="POST" action="{{ route('support.return', $ticket->id) }}" class="form tkt-act">
                        @csrf

                        @if ($ticket->returnsToCoordinator && $ticket->coordinatorOptions === [])
                            <p class="note note--warn" role="note">
                                <x-ui.icon name="warn" />
                                <span>{{ __('support.actions.no_coordinator') }}</span>
                            </p>
                        @elseif ($ticket->returnsToCoordinator)
                            <x-ui.select name="coordinator_id" required :placeholder="false"
                                :label="__('support.actions.return_coordinator')">
                                @foreach ($ticket->coordinatorOptions as $option)
                                    <option value="{{ $option['value'] }}" @selected(old('coordinator_id', $ticket->defaultCoordinator) === $option['value'])>{{ $option['label'] }}</option>
                                @endforeach
                            </x-ui.select>
                        @endif

                        <x-ui.textarea name="return_note" rows="2" :maxlength="$bodyMax"
                            :label="__('support.actions.return_note')"
                            :hint="__('support.actions.return_hint', ['to' => $ticket->returnTo])"
                            :value="old('return_note')" />

                        <div class="form__submit">
                            <x-ui.button variant="secondary" type="submit" icon="undo">{{ __('support.actions.return', ['to' => $ticket->returnTo]) }}</x-ui.button>
                        </div>
                    </form>
                @endif

                @if ($ticket->canAssign && $ticket->assignOptions !== [])
                    <form method="POST" action="{{ route('support.assign', $ticket->id) }}" class="form tkt-act">
                        @csrf

                        <x-ui.select name="assignee_id" required
                            :label="__('support.actions.assign')"
                            :placeholder="__('support.actions.assign_coordinator')">
                            @foreach ($ticket->assignOptions as $option)
                                <option value="{{ $option['value'] }}" @selected(old('assignee_id') === $option['value'])>{{ $option['label'] }}</option>
                            @endforeach
                        </x-ui.select>

                        <x-ui.textarea name="assign_note" rows="2" :maxlength="$bodyMax"
                            :label="__('support.actions.assign_note')"
                            :hint="__('support.actions.assign_hint')"
                            :value="old('assign_note')" />

                        <div class="form__submit">
                            <x-ui.button variant="secondary" type="submit" icon="user">{{ __('support.actions.assign_submit') }}</x-ui.button>
                        </div>
                    </form>
                @endif
            </div>
        </x-ui.card>
    @endif
@endsection
