{{--
    Support — a new ticket (D-124). A participant's only.

    A subject, a category, a description, and optionally a link and up to
    three pictures or videos. The form is a courtesy: OpenTicketRequest checks
    every field again, and PrivateFileService sniffs every file's real type
    from its bytes before anything reaches the disk (art. 24).

    @see D-124 · CONSTITUTION art. 5, art. 17, art. 24
--}}
@extends('layouts.app')

@section('title', __('support.create.title'))
@section('subtitle', __('support.create.subtitle'))

@section('content')
    <x-ui.card class="dc--span" icon="help" :title="__('support.create.title')">
        <x-slot:action>
            <a href="{{ route('support.index') }}">{{ __('support.show.back') }}</a>
        </x-slot:action>

        <p class="note note--info" role="note">
            <x-ui.icon name="info" />
            <span>{{ __('support.create.intro') }}</span>
        </p>

        <form method="POST" action="{{ route('support.store') }}" enctype="multipart/form-data" class="form u-mt-4">
            @csrf

            <x-ui.input name="subject" required :maxlength="$subjectMax"
                :label="__('support.create.subject')"
                :hint="__('support.create.subject_hint')"
                :value="old('subject')" />

            {{-- A native select: four choices, and it works without JavaScript. --}}
            <x-ui.select name="category" required
                :label="__('support.create.category')"
                :placeholder="__('support.create.category_placeholder')">
                @foreach ($categories as $category)
                    <option value="{{ $category['value'] }}" @selected(old('category') === $category['value'])>{{ $category['label'] }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.textarea name="body" rows="6" required :maxlength="$bodyMax"
                :label="__('support.create.body')"
                :hint="__('support.create.body_hint')"
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
                <x-ui.button variant="primary" type="submit" icon="send">{{ __('support.create.submit') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@endsection
