{{--
    Account. Latin fields (email, phone, GitHub) carry dir="ltr" inside the control
    while the label stays RTL. Changing the password invalidates every active session
    (BR-29) — the server does it; this page only warns.

    In preview mode every form is removed, not merely disabled, and the write endpoints
    reject the request anyway (BR-33).

    @see PRD §9.5.2, §9.16 · BR-29, BR-33
--}}
@extends('layouts.app')

@section('title', __('nav.profile'))
@section('subtitle', __('profile.subtitle'))

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('profile.error_title')"
            :description="__('profile.error_body')"
            :action-label="__('app.retry')" :action-href="route('profile')" />
    @elseif (is_null($profile))
        <div class="dgrid">
            @for ($i = 0; $i < 3; $i++)
                <x-ui.card>
                    <x-ui.skeleton height="var(--s4)" width="34%" />
                    <x-ui.skeleton height="var(--touch-min)" width="100%" class="u-mt-2" />
                    <x-ui.skeleton height="var(--touch-min)" width="100%" class="u-mt-2" />
                </x-ui.card>
            @endfor
        </div>
    @else
        @if ($isImpersonating)
            <div class="note note--warn" role="status">
                <b>{{ __('profile.preview_readonly_title') }}</b>
                {{ __('profile.preview_readonly_body') }}
            </div>
        @endif

        <div class="dgrid">
            {{-- Identity ------------------------------------------------------- --}}
            <x-ui.card class="dc--2" icon="user" :title="__('profile.identity_title')">
                @unless ($isImpersonating)
                    <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
                        @csrf
                        @method('PATCH')

                        <div class="profile__photo">
                            <x-ui.avatar size="lg" :name="$profile->fullNameAr" :src="$profile->photoUrl" />
                            <div>
                                <x-ui.button variant="secondary" size="sm" type="button"
                                    x-data x-on:click="$refs.photo.click()">{{ __('profile.change_photo') }}</x-ui.button>
                                <input type="file" name="photo" accept="image/*" class="sr" x-ref="photo"
                                    aria-label="{{ __('profile.change_photo') }}">
                                <p class="hint">{{ __('profile.photo_hint') }}</p>
                            </div>
                        </div>

                        <div class="f2">
                            <x-ui.input name="first_name_ar" :label="__('profile.first_name_ar')" required
                                :value="old('first_name_ar', $profile->firstNameAr)" />
                            <x-ui.input name="father_name_ar" :label="__('profile.father_name_ar')" required
                                :value="old('father_name_ar', $profile->fatherNameAr)" />
                            <x-ui.input name="grandfather_name_ar" :label="__('profile.grandfather_name_ar')" required
                                :value="old('grandfather_name_ar', $profile->grandfatherNameAr)" />
                            <x-ui.input name="family_name_ar" :label="__('profile.family_name_ar')" required
                                :value="old('family_name_ar', $profile->familyNameAr)" />
                        </div>

                        <div class="f2">
                            <x-ui.input name="first_name_en" dir="ltr" :label="__('profile.first_name_en')" required
                                :value="old('first_name_en', $profile->firstNameEn)" />
                            <x-ui.input name="family_name_en" dir="ltr" :label="__('profile.family_name_en')" required
                                :value="old('family_name_en', $profile->familyNameEn)" />
                        </div>

                        <div class="f2">
                            <x-ui.input name="phone" type="tel" dir="ltr" :label="__('profile.phone')" required
                                :hint="__('profile.phone_hint')"
                                :value="old('phone', $profile->phone)" />
                            <x-ui.input name="email" type="email" dir="ltr" :label="__('profile.email')" readonly
                                :hint="__('profile.email_hint')"
                                :value="$profile->email" />
                        </div>

                        <div class="row__acts">
                            <x-ui.button variant="primary" type="submit">{{ __('app.save_changes') }}</x-ui.button>
                        </div>
                    </form>
                @else
                    <dl class="deflist">
                        <div><dt>{{ __('profile.full_name_ar') }}</dt><dd>{{ $profile->fullNameAr }}</dd></div>
                        <div><dt>{{ __('profile.full_name_en') }}</dt><dd dir="ltr">{{ $profile->fullNameEn }}</dd></div>
                        <div><dt>{{ __('profile.email') }}</dt><dd dir="ltr">{{ $profile->email }}</dd></div>
                        <div><dt>{{ __('profile.phone') }}</dt><dd dir="ltr">{{ $profile->phone }}</dd></div>
                    </dl>
                @endunless
            </x-ui.card>

            {{-- Security -------------------------------------------------------- --}}
            <x-ui.card icon="shield" :title="__('profile.security_title')">
                @unless ($isImpersonating)
                    <form method="POST" action="{{ route('profile.password') }}">
                        @csrf
                        @method('PUT')
                        <x-ui.input name="current_password" type="password" dir="ltr"
                            :label="__('profile.current_password')" required autocomplete="current-password" />
                        <x-ui.input name="password" type="password" dir="ltr"
                            :label="__('profile.new_password')" required autocomplete="new-password"
                            :hint="__('profile.password_hint')" />
                        <x-ui.input name="password_confirmation" type="password" dir="ltr"
                            :label="__('profile.confirm_password')" required autocomplete="new-password" />

                        <div class="note note--warn">
                            <b>{{ __('profile.password_signout_title') }}</b>
                            {{ __('profile.password_signout_body') }}
                        </div>

                        <div class="row__acts">
                            <x-ui.button variant="primary" type="submit">{{ __('profile.update_password') }}</x-ui.button>
                        </div>
                    </form>
                @else
                    <x-ui.empty-state icon="lock" size="sm" variant="muted"
                        :title="__('profile.preview_readonly_title')"
                        :description="__('profile.preview_readonly_body')" />
                @endunless
            </x-ui.card>

            {{-- Sessions ---------------------------------------------------------- --}}
            <x-ui.card icon="globe" :title="__('profile.sessions_title')">
                @if ($sessions->isEmpty())
                    <x-ui.empty-state icon="globe" size="sm"
                        :title="__('profile.sessions_empty_title')"
                        :description="__('profile.sessions_empty_body')" />
                @else
                    @foreach ($sessions as $device)
                        <div class="row">
                            <div class="row__m">
                                <b>{{ $device->deviceLabel }}</b>
                                <span>
                                    <span dir="ltr">{{ $device->ipAddress }}</span>
                                    · {{ \App\Support\Dates::relative($device->lastActiveAt) }}
                                </span>
                            </div>
                            <div class="row__e">
                                @if ($device->isCurrent)
                                    <x-ui.pill variant="success" icon="check">{{ __('profile.current_device') }}</x-ui.pill>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    @unless ($isImpersonating)
                        <form method="POST" action="{{ route('profile.sessions.destroy') }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button variant="danger" size="sm" type="submit">{{ __('profile.sign_out_everywhere') }}</x-ui.button>
                        </form>
                    @endunless
                @endif
            </x-ui.card>

            {{-- Notification preferences ------------------------------------------ --}}
            <x-ui.card class="dc--span" icon="bell" :title="__('notifications.preferences_title')">
                @if ($preferences->isEmpty())
                    <x-ui.empty-state icon="bell" size="sm"
                        :title="__('notifications.preferences_empty_title')"
                        :description="__('notifications.preferences_empty_body')" />
                @else
                    <form method="POST" action="{{ route('profile.notifications') }}">
                        @csrf
                        @method('PUT')
                        <div class="tscroll">
                            <table class="atable">
                                <caption class="sr">{{ __('notifications.preferences_title') }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('notifications.event') }}</th>
                                        <th scope="col">{{ __('notifications.channel_platform') }}</th>
                                        <th scope="col">{{ __('notifications.channel_email') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($preferences as $preference)
                                        <tr>
                                            <th scope="row">{{ $preference->label }}</th>
                                            <td>
                                                <x-ui.toggle name="prefs[{{ $preference->key }}][platform]"
                                                    :checked="$preference->platform"
                                                    :disabled="$isImpersonating || ! $preference->platformEditable"
                                                    :label="__('notifications.toggle_aria', ['event' => $preference->label, 'channel' => __('notifications.channel_platform')])" />
                                            </td>
                                            <td>
                                                <x-ui.toggle name="prefs[{{ $preference->key }}][email]"
                                                    :checked="$preference->email"
                                                    :disabled="$isImpersonating || ! $preference->emailEditable"
                                                    :label="__('notifications.toggle_aria', ['event' => $preference->label, 'channel' => __('notifications.channel_email')])" />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @unless ($isImpersonating)
                            <div class="row__acts">
                                <x-ui.button variant="primary" type="submit">{{ __('app.save_changes') }}</x-ui.button>
                            </div>
                        @endunless
                    </form>
                @endif
            </x-ui.card>
        </div>
    @endif
@endsection
