{{--
    The receipt of a final-project hand-in — the page its QR code opens
    (D-122).

    Reached only past the hand-in's own policy (FinalProjectReceiptController):
    its owner, the cohort's trainer, the general supervisor. The owner is
    pointed back to the project (and to the grade once there is one); staff to
    the grading panel. States: normal and error — the page exists only for a
    hand-in that exists, so it has no empty state, and no list to load.

    @see PRD §9.14.2 · FR-NOTIF-15 · BR-22, BR-23 · D-122
--}}
@extends('layouts.app')

@section('title', __('project.receipt.title'))
@section('subtitle', $receipt->projectTitle)

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('project.error_title')"
            :description="__('project.error_body')"
            :action-label="__('app.retry')" :action-href="$receipt->url" />
    @else
        <x-ui.card class="dc--span" icon="check" :title="__('project.receipt.title')">
            @include('partials.hand-in-receipt', ['receipt' => $receipt])

            <dl class="deflist u-mt-4">
                <div>
                    <dt>{{ __('project.receipt.details.project') }}</dt>
                    <dd>{{ $receipt->projectTitle }}</dd>
                </div>
                <div>
                    <dt>{{ __('project.receipt.details.participant') }}</dt>
                    <dd>{{ $receipt->participantName }}</dd>
                </div>
                <div>
                    <dt>{{ __('project.receipt.details.submitted_at') }}</dt>
                    <dd class="u-num">
                        {{ \App\Support\Dates::dateTime($receipt->submittedAt) }}
                        @if ($receipt->isLate)
                            <x-ui.pill variant="warning" icon="clock">{{ __('project.receipt.late') }}</x-ui.pill>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt>{{ __('project.receipt.details.version') }}</dt>
                    <dd>{{ $receipt->versionLabel }}</dd>
                </div>
                @if ($receipt->items !== [])
                    <div>
                        <dt>{{ __('project.receipt.details.items') }}</dt>
                        <dd>
                            <ul class="note__list">
                                @foreach ($receipt->items as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        </dd>
                    </div>
                @endif
            </dl>

            <div class="row__acts u-mt-4">
                @if ($receipt->gradingUrl)
                    <x-ui.button variant="primary" :href="$receipt->gradingUrl">{{ __('project.receipt.open_grading') }}</x-ui.button>
                @endif
                @if ($receipt->gradesUrl)
                    <x-ui.button variant="primary" :href="$receipt->gradesUrl">{{ __('nav.grades') }}</x-ui.button>
                @endif
                @if ($receipt->projectUrl)
                    <x-ui.button variant="secondary" :href="$receipt->projectUrl">{{ __('project.receipt.open_project') }}</x-ui.button>
                @endif
            </div>
        </x-ui.card>
    @endif
@endsection
