{{--
    Admin · general settings (BR-36).

    Centre contact details, upload limits and the default notification preferences are
    SHOWN here, read-only: they live in config/athar.php, which reads the environment
    (BR-36), so changing them is a deployment, not a click (D-148 — the form that looked
    editable and saved nothing was removed). The e-mail templates are the part that really
    saves, and they stay editable (D-136).

    The timezone is deliberately NOT editable: storage is UTC and display is
    Asia/Riyadh, and server time is the single reference for every calculation
    (BR-07). The field is shown read-only with the reason next to it, rather than
    hidden, so nobody goes looking for it in the code.

    Four states: error · loading skeleton shaped like the list · empty (no e-mail
    templates seeded yet) · normal.

    @see PRD §9.18, §12.5 · BR-07, BR-27, BR-31, BR-36 · D-148
--}}
@extends('layouts.app')

@section('title', __('admin.settings.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.settings.edit')" />
    @elseif (is_null($settings))
        <x-ui.card class="dc--span">
            <x-ui.skeleton height="var(--s5)" width="var(--d-3)" />
            <x-ui.skeleton height="var(--touch-min)" width="100%" class="u-mt-4" />
            <x-ui.skeleton height="var(--touch-min)" width="100%" class="u-mt-2" />
            <x-ui.skeleton height="var(--touch-min)" width="100%" class="u-mt-2" />
        </x-ui.card>
    @else

        {{-- Centre information: what is deployed, read-only (D-148) ---------------------- --}}
        <x-ui.card class="dc--span" icon="globe" :title="__('admin.settings.centre_info')">
            {{-- The values live in config/athar.php, which reads the environment (BR-36). A form that
                 looked editable and saved nothing was removed (D-148); this says where they are set. --}}
            <p class="hint">
                <x-ui.icon name="lock" />
                {{ __('admin.settings.readonly_note') }}
            </p>

            <div class="f2">
                <dl class="deflist">
                    <div><dt>{{ __('admin.settings.fields.centre_name') }}</dt><dd>{{ $settings->centreName }}</dd></div>
                    <div><dt>{{ __('admin.settings.fields.program_name') }}</dt><dd>{{ $settings->programName }}</dd></div>
                    <div><dt>{{ __('admin.settings.fields.contact_email') }}</dt><dd dir="ltr">{{ $settings->contactEmail }}</dd></div>
                    <div><dt>{{ __('admin.settings.fields.whatsapp') }}</dt><dd dir="ltr">{{ $settings->whatsapp }}</dd></div>
                </dl>

                <dl class="deflist">
                    <div>
                        <dt>{{ __('admin.settings.timezone') }}</dt>
                        <dd dir="ltr">{{ $settings->timezone }}</dd>
                    </div>
                    <div><dt>{{ __('admin.settings.fields.max_file_mb') }}</dt><dd><span class="u-num">{{ $settings->maxFileMb }}</span></dd></div>
                    <div><dt>{{ __('admin.settings.fields.max_files') }}</dt><dd><span class="u-num">{{ $settings->maxFiles }}</span></dd></div>
                </dl>
            </div>

            <p class="hint">{{ __('admin.settings.timezone_note') }}</p>
            <p class="hint">
                <x-ui.icon name="shield" />
                {{ __('admin.settings.fields.upload_allowlist_note') }}
            </p>
        </x-ui.card>

        {{-- Default notification preferences: how it really is, read-only (D-148) -------- --}}
        <x-ui.card class="dc--2 u-mt-4" icon="bell" :title="__('admin.settings.default_notifications')">
            @if ($settings->notificationDefaults->isEmpty())
                <x-ui.empty-state icon="bell"
                    :title="__('admin.settings.notifications_empty_title')"
                    :description="__('admin.settings.notifications_empty_body')" />
            @else
                <p class="hint">{{ __('admin.settings.defaults_note') }}</p>

                @foreach ($settings->notificationDefaults as $preference)
                    <div class="row">
                        <div class="row__m">
                            <b>{{ $preference->label }}</b>
                            <span>{{ $preference->description }}</span>
                        </div>
                        <div class="row__e">
                            @if ($preference->locked)
                                <x-ui.pill variant="primary" icon="lock">{{ __('admin.settings.always_on') }}</x-ui.pill>
                            @else
                                <x-ui.pill variant="neutral" icon="check">{{ __('admin.settings.default_on') }}</x-ui.pill>
                            @endif
                        </div>
                    </div>
                @endforeach
            @endif
        </x-ui.card>

        {{-- E-mail templates ----------------------------------------------------------- --}}
        <x-ui.card class="dc--2 u-mt-4" icon="mail" :title="__('admin.settings.email_templates')">
            @if ($settings->emailTemplates->isEmpty())
                <x-ui.empty-state icon="mail"
                    :title="__('admin.settings.templates_empty_title')"
                    :description="__('admin.settings.templates_empty_body')" />
            @else
                @foreach ($settings->emailTemplates as $template)
                    <div class="row">
                        <div class="row__m">
                            <b>{{ $template->label }}</b>
                            @if ($template->isCustomised)
                                <x-ui.pill variant="primary" icon="pencil">{{ __('admin.email_editor.customised') }}</x-ui.pill>
                            @endif
                            <span>{{ $template->subject }}</span>
                        </div>
                        <div class="row__e">
                            <x-ui.button icon="pencil" :icon-only="true" variant="secondary" size="sm" :context="$template->label"
                                :href="route('admin.settings.template', $template->key)">{{ __('app.edit') }}</x-ui.button>
                        </div>
                    </div>
                @endforeach
            @endif
        </x-ui.card>

        <p class="footnote">{{ __('admin.users.constraints.audited') }}</p>
    @endif
@endsection
