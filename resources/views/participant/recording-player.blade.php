{{--
    The one page a recording's actual host is ever disclosed on. Reaching it
    already passed LiveController::recording()'s policy and Completed-status
    check; the src below is the plain, server-validated url that guard handed
    over, never markup a coordinator or trainer pasted anywhere (D-107,
    CONSTITUTION Art. 24).

    @see BR-22 · PRD §9.10
--}}
@extends('layouts.app')

@section('title', $recording->topic)
@section('subtitle', __('live.recordings_title'))

@section('content')
    <x-ui.card icon="video" :title="$recording->topic">
        <div class="recording-embed">
            <iframe src="{{ $recording->embedUrl }}" allowfullscreen title="{{ $recording->topic }}"></iframe>
        </div>

        <div class="u-mt-4">
            <x-ui.button variant="secondary" size="sm" :href="route('live')">{{ __('app.back') }}</x-ui.button>
        </div>
    </x-ui.card>
@endsection
