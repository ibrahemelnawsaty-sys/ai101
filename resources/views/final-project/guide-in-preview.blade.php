{{--
    The final project's guide, asked for during an account preview (D-129).

    The guide is a whole document of its own, served outside the platform's
    shell — so the preview banner that must stay on screen for the whole
    preview (Article 23) would not be there. Until the owner decides, the
    preview is shown this page, inside the shell and its banner, instead.

    @see D-129 · D-127 · BR-33, BR-34 · CONSTITUTION Art. 23
--}}
@extends('layouts.app')

@section('title', __('project.guide.preview_title'))

@section('content')
    <x-ui.card class="dc--span">
        <x-ui.empty-state icon="eye"
            :title="__('project.guide.preview_title')"
            :description="__('project.guide.preview_body')"
            :action-label="__('nav.final_project')" :action-href="$backUrl" />
    </x-ui.card>
@endsection
