{{--
    Admin · general settings (BR-36).

    Centre contact details, e-mail templates, upload limits and the default
    notification preferences. Everything here is content, and BR-36 is the reason
    it lives in the database rather than in a constant somewhere.

    The timezone is deliberately NOT editable: storage is UTC and display is
    Asia/Riyadh, and server time is the single reference for every calculation
    (BR-07). The field is shown read-only with the reason next to it, rather than
    hidden, so nobody goes looking for it in the code.

    Four states: error · loading skeleton shaped like the form · empty (no e-mail
    templates seeded yet) · normal.

    @see PRD §9.18, §12.5 · BR-07, BR-27, BR-31, BR-36
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

        {{-- Centre information ------------------------------------------------------ --}}
        <x-ui.card class="dc--span" icon="globe" :title="__('admin.settings.centre_info')">
            <form method="POST" action="{{ route('admin.settings.update') }}">
                @csrf
                @method('PATCH')

                <div class="f2">
                    <x-ui.input name="centre_name" required
                        :label="__('admin.settings.fields.centre_name')"
                        :value="old('centre_name', $settings->centreName)" />
                    <x-ui.input name="program_name" required
                        :label="__('admin.settings.fields.program_name')"
                        :hint="__('admin.settings.fields.program_name_hint')"
                        :value="old('program_name', $settings->programName)" />
                </div>

                <div class="f2">
                    <x-ui.input name="contact_email" type="email" dir="ltr" required
                        :label="__('admin.settings.fields.contact_email')"
                        :value="old('contact_email', $settings->contactEmail)" />
                    <x-ui.input name="whatsapp" type="tel" dir="ltr"
                        :label="__('admin.settings.fields.whatsapp')"
                        :value="old('whatsapp', $settings->whatsapp)" />
                </div>

                <x-ui.input name="timezone" readonly dir="ltr"
                    :label="__('admin.settings.timezone')"
                    :hint="__('admin.settings.timezone_note')"
                    :value="$settings->timezone" />

                <h3 class="abrief__sub">{{ __('admin.settings.upload_limits') }}</h3>

                <div class="f2">
                    <x-ui.input name="max_file_mb" type="number" min="1" step="1" required
                        :label="__('admin.settings.fields.max_file_mb')"
                        :value="old('max_file_mb', $settings->maxFileMb)" />
                    <x-ui.input name="max_files" type="number" min="1" step="1" required
                        :label="__('admin.settings.fields.max_files')"
                        :value="old('max_files', $settings->maxFiles)" />
                </div>

                <p class="hint">
                    <x-ui.icon name="shield" />
                    {{ __('admin.settings.fields.upload_allowlist_note') }}
                </p>

                <div class="row__acts">
                    <x-ui.button variant="primary" type="submit">{{ __('app.save_changes') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        {{-- Default notification preferences ----------------------------------------- --}}
        <x-ui.card class="dc--2 u-mt-4" icon="bell" :title="__('admin.settings.default_notifications')">
            @if ($settings->notificationDefaults->isEmpty())
                <x-ui.empty-state icon="bell"
                    :title="__('admin.settings.notifications_empty_title')"
                    :description="__('admin.settings.notifications_empty_body')" />
            @else
                <form method="POST" action="{{ route('admin.settings.notifications') }}">
                    @csrf
                    @method('PATCH')

                    @foreach ($settings->notificationDefaults as $preference)
                        <div class="row">
                            <div class="row__m">
                                <b>{{ $preference->label }}</b>
                                <span>{{ $preference->description }}</span>
                            </div>
                            <div class="row__e">
                                <x-ui.toggle name="defaults[{{ $preference->key }}][platform]"
                                    :checked="$preference->platform"
                                    :disabled="! $preference->platformEditable"
                                    :label="__('notifications.toggle_aria', ['event' => $preference->label, 'channel' => __('notifications.channel_platform')])" />
                                <x-ui.toggle name="defaults[{{ $preference->key }}][email]"
                                    :checked="$preference->email"
                                    :disabled="! $preference->emailEditable"
                                    :label="__('notifications.toggle_aria', ['event' => $preference->label, 'channel' => __('notifications.channel_email')])" />
                            </div>
                        </div>
                    @endforeach

                    <div class="row__acts">
                        <x-ui.button variant="primary" size="sm" type="submit">{{ __('app.save_changes') }}</x-ui.button>
                    </div>
                </form>
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
                            <span>{{ $template->subject }}</span>
                        </div>
                        <div class="row__e">
                            <x-ui.button variant="secondary" size="sm"
                                :href="route('admin.settings.template', $template->key)">{{ __('app.edit') }}</x-ui.button>
                        </div>
                    </div>
                @endforeach
            @endif
        </x-ui.card>

        <p class="footnote">{{ __('admin.users.constraints.audited') }}</p>
    @endif
@endsection
