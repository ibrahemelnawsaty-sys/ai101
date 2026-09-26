{{--
    The items of one final-project hand-in (D-121), each under the label it was
    asked by and in the order the form asked for it. Shared by the
    participant's own screen and the trainer's grading panel, so the two can
    never show the same hand-in two ways.

    Every file link is a signed URL valid fifteen minutes, minted after the
    page's permission check; the download route asks the policy again (D-80).
    An address is linked only when the presenter vouched for it (`href`).

    Expects: $answers — list of App\Presenters\Shared\HandInAnswer

    @see PRD §9.14.2, §12.5 · FR-PROJ-09, FR-PROJ-10 · BR-19, BR-22, BR-23 · D-80, D-121
--}}
@if (count($answers) === 0)
    <p class="u-muted">{{ __('app.none') }}</p>
@else
    <dl class="deflist">
        @foreach ($answers as $answer)
            <div>
                <dt>{{ $answer->label }}</dt>
                @if ($answer->isEmpty)
                    <dd><span class="u-muted">{{ __('app.none') }}</span></dd>
                @elseif ($answer->isFile)
                    <dd>
                        <ul class="filelist">
                            @foreach ($answer->files as $file)
                                <li>
                                    @if ($file->downloadUrl)
                                        <a href="{{ $file->downloadUrl }}">
                                            <x-ui.icon name="file" /><span dir="ltr">{{ $file->name }}</span>
                                        </a>
                                    @else
                                        <span dir="ltr">{{ $file->name }}</span>
                                    @endif
                                    <small class="u-num">{{ $file->sizeLabel }}</small>
                                </li>
                            @endforeach
                        </ul>
                    </dd>
                @elseif ($answer->href)
                    <dd><a href="{{ $answer->href }}" dir="ltr" target="_blank" rel="noopener nofollow">{{ $answer->value }}</a></dd>
                @elseif ($answer->isLongText)
                    <dd class="deflist__long">{{ $answer->value }}</dd>
                @else
                    <dd><span @if ($answer->isLink) dir="ltr" @endif>{{ $answer->value }}</span></dd>
                @endif
            </div>
        @endforeach
    </dl>
@endif
