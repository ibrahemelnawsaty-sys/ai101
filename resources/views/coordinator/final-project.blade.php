{{--
    Coordinator · the final project tab (D-127).

    The general supervisor enters the project and its guide and makes them
    available; the cohort's PRIMARY coordinator publishes them to the trainees.
    Every coordinator of the cohort reads this screen; a button shows only when
    the same policy that guards its route would allow the press — and hiding it
    is still not the control (Article 5).

    No hand-ins and no marks here: the role is preparation (D-105).

    Four states: error · empty (no cohort, or no project yet) · normal. The page
    is server-rendered in one response, so there is no separate loading state
    to draw (the pattern of trainer.final-project).

    @see D-127 · D-105, D-124 · CONSTITUTION Art. 5, Art. 17
--}}
@extends('layouts.app')

@section('title', __('coordinator.final_project.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('coordinator.final_project.error_title')"
            :description="__('coordinator.final_project.error_body')"
            :action-label="__('app.retry')" :action-href="route('coordinator.finalProject')" />

    @elseif (! $hasCohort)
        <x-ui.empty-state icon="spark"
            :title="__('coordinator.final_project.no_cohort_title')"
            :description="__('coordinator.final_project.no_cohort_body')"
            :action-label="__('nav.coordinator.dashboard')" :action-href="route('coordinator.dashboard')" />

    @elseif ($panel === null)
        <x-ui.empty-state icon="spark"
            :title="__('coordinator.final_project.no_project_title')"
            :description="__('coordinator.final_project.no_project_body')"
            :action-label="__('nav.coordinator.dashboard')" :action-href="route('coordinator.dashboard')" />

    @else
        <p class="form__note">{{ __('coordinator.final_project.intro') }}</p>

        @unless ($panel->hasPrimary)
            <div class="note note--warn u-mt-2" role="status">
                <x-ui.icon name="warn" />
                <p>{{ __('coordinator.final_project.no_primary') }}</p>
            </div>
        @endunless

        @if ($panel->hasPrimary && ! $panel->isPrimary)
            <p class="hint u-mt-2"><x-ui.icon name="info" /><span>{{ __('coordinator.final_project.primary_only') }}</span></p>
        @endif

        {{-- The project -------------------------------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="spark" id="publish-project" :title="$panel->title">
            <x-slot:action>
                <x-ui.pill :variant="$panel->stateVariant === 'muted' ? 'neutral' : $panel->stateVariant"
                    :icon="$panel->isPublished ? 'check' : ($panel->isAvailable ? 'clock' : 'lock')">{{ $panel->stateLabel }}</x-ui.pill>
            </x-slot:action>

            <p class="u-num">{{ __('coordinator.final_project.due', ['date' => $panel->dueLabel]) }}</p>
            <p class="note__body">{{ $panel->handInLabel }}</p>
            @if ($panel->publishedNote)
                <p class="footnote">{{ $panel->publishedNote }}</p>
            @endif

            @if ($panel->isPrimary && ! $panel->isAvailable)
                <p class="hint"><x-ui.icon name="info" /><span>{{ __('coordinator.final_project.waiting_available') }}</span></p>
            @endif

            @if ($panel->canPublish)
                <form method="POST" action="{{ $panel->publicationAction }}" class="row__acts u-mt-4">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="published" value="1">
                    <x-ui.button variant="primary" type="submit" icon="check">{{ __('coordinator.final_project.publish') }}</x-ui.button>
                </form>
            @elseif ($panel->canUnpublish)
                @if ($panel->handInCount > 0 && ! $panel->confirmingUnpublish)
                    <div class="row__acts u-mt-4">
                        <x-ui.button variant="secondary" icon="lock" :href="$panel->confirmUrl">{{ __('coordinator.final_project.unpublish') }}</x-ui.button>
                    </div>
                @elseif ($panel->handInCount === 0)
                    <form method="POST" action="{{ $panel->publicationAction }}" class="row__acts u-mt-4">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="published" value="0">
                        <x-ui.button variant="secondary" type="submit" icon="lock">{{ __('coordinator.final_project.unpublish') }}</x-ui.button>
                    </form>
                @endif
            @endif

            @error('confirmed')
                <p class="ui-field__error" role="alert">{{ $message }}</p>
            @enderror
        </x-ui.card>

        {{-- Confirming an unpublish after hand-ins arrived -------------------------- --}}
        @if ($panel->confirmingUnpublish)
            <x-ui.card class="dc--span u-mt-4" icon="warn" :title="__('coordinator.final_project.confirm_title')">
                <div class="note note--warn" role="alert">
                    <x-ui.icon name="warn" />
                    <p>{{ __('coordinator.final_project.confirm_body', ['count' => $panel->handInLabel]) }}</p>
                </div>

                <form method="POST" action="{{ $panel->publicationAction }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="published" value="0">
                    <input type="hidden" name="confirmed" value="1">
                    <div class="row__acts">
                        <x-ui.button variant="danger" type="submit">{{ __('coordinator.final_project.confirm_action') }}</x-ui.button>
                        <x-ui.button variant="ghost" :href="$panel->cancelUrl">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        {{-- The guide ---------------------------------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="file" id="publish-guide" :title="__('coordinator.final_project.guide_title')">
            <p class="form__note">{{ __('coordinator.final_project.guide_intro') }}</p>

            <ul class="reslist u-mt-4" role="list">
                @foreach ($panel->languages as $language)
                    <li class="row">
                        <div class="row__m">
                            <b>{{ $language['label'] }}</b>
                            <span class="u-inline">
                                <x-ui.pill :variant="$language['stateVariant'] === 'muted' ? 'neutral' : $language['stateVariant']">{{ $language['stateLabel'] }}</x-ui.pill>
                            </span>
                            @if ($language['waitsForPrimary'])
                                <span class="note__body">{{ __('coordinator.final_project.guide_needs_arabic') }}</span>
                            @endif
                        </div>

                        <div class="row__e row__acts">
                            @if ($language['canView'])
                                <x-ui.button variant="ghost" size="sm" icon="external" :href="$language['viewUrl']"
                                    target="_blank" rel="noopener" aria-label="{{ __('coordinator.final_project.guide_view_label') }}">{{ __('coordinator.final_project.guide_view') }}</x-ui.button>
                            @endif

                            @if ($language['canPublish'])
                                <form method="POST" action="{{ $language['action'] }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="published" value="1">
                                    <x-ui.button variant="primary" size="sm" type="submit" icon="check">{{ __('coordinator.final_project.guide_publish') }}</x-ui.button>
                                </form>
                            @elseif ($language['canUnpublish'])
                                <form method="POST" action="{{ $language['action'] }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="published" value="0">
                                    <x-ui.button variant="secondary" size="sm" type="submit" icon="lock">{{ __('coordinator.final_project.guide_unpublish') }}</x-ui.button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
@endsection
