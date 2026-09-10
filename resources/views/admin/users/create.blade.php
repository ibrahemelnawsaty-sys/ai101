{{--
    Admin · create one account and invite it.

    WHY THIS FILE DID NOT EXIST
    The route, the controller method, the FormRequest and the policy were all
    written. `create()` returned `admin.users.index` with `'creating' => true`,
    and that template never read the flag — so pressing "add a user" rendered
    the same list again and looked like a dead button. There was no create form
    anywhere in the platform, which is why an administrator had no way to bring
    a trainee in once registration closed (D-63).

    NO PASSWORD FIELD, DELIBERATELY. The platform generates a temporary one and
    mails it; the trainee replaces it at their first sign-in. An administrator
    who chooses a trainee's password can sign in as them without leaving the
    impersonation record BR-34 requires.

    THE COHORT IS THE FIELD THAT MATTERS. Without it the account is created
    outside every cohort — no assignments, no sessions, no card, no certificate
    path — which is exactly what this form used to produce.

    The field labels are the registration form's own keys, not new ones: the two
    forms ask for the same eleven facts, and a second set of labels is a second
    thing to keep in step (Article 6).

    Four states: error · loading · empty (no cohort exists yet) · normal.

    @see PRD §4.5.1, §9.2.1 · BR-22, BR-32, BR-34 · D-63
--}}
@extends('layouts.app')

@section('title', __('admin.users.create_title'))
@section('subtitle', __('admin.users.create_subtitle'))

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.users.create')" />
    @elseif (count($cohortOptions) === 0)
        {{-- A participant cannot be created without somewhere to seat them, and
             an empty state that only said "nothing here" would leave the
             administrator guessing what to do next. --}}
        <x-ui.empty-state icon="users"
            :title="__('admin.users.no_cohort_title')"
            :description="__('admin.users.no_cohort_body')"
            :action-label="__('admin.users.no_cohort_action')" :action-href="route('admin.cohorts.index')" />
    @else
        @if ($errors->any())
            <div class="note note--bad" role="alert" aria-live="polite">
                <b>{{ __('auth.shared.error_summary_title') }}</b>
                <ul>
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.users.store') }}" class="form">
            @csrf

            <x-ui.card icon="user" :title="__('admin.users.section_identity')">
                <fieldset class="fieldset">
                    <legend>{{ __('auth.register.ar_names') }}</legend>
                    <div class="grid-fields">
                        <x-ui.input name="first_name_ar" required maxlength="20"
                            :label="__('auth.register.first_name_ar')" :value="old('first_name_ar')" />
                        <x-ui.input name="father_name_ar" required maxlength="20"
                            :label="__('auth.register.father_name_ar')" :value="old('father_name_ar')" />
                        <x-ui.input name="grandfather_name_ar" required maxlength="20"
                            :label="__('auth.register.grandfather_name_ar')" :value="old('grandfather_name_ar')" />
                        <x-ui.input name="family_name_ar" required maxlength="20"
                            :label="__('auth.register.family_name_ar')" :value="old('family_name_ar')" />
                    </div>
                </fieldset>

                {{-- Latin names read left-to-right inside the box while their
                     labels stay right-to-left (Article 16). --}}
                <fieldset class="fieldset">
                    <legend>{{ __('auth.register.en_names') }}</legend>
                    <div class="grid-fields">
                        <x-ui.input name="first_name_en" required ltr maxlength="20"
                            :label="__('auth.register.first_name_en')" :value="old('first_name_en')" />
                        <x-ui.input name="father_name_en" required ltr maxlength="20"
                            :label="__('auth.register.father_name_en')" :value="old('father_name_en')" />
                        <x-ui.input name="grandfather_name_en" required ltr maxlength="20"
                            :label="__('auth.register.grandfather_name_en')" :value="old('grandfather_name_en')" />
                        <x-ui.input name="family_name_en" required ltr maxlength="20"
                            :label="__('auth.register.family_name_en')" :value="old('family_name_en')" />
                    </div>
                </fieldset>
            </x-ui.card>

            <x-ui.card icon="mail" :title="__('admin.users.section_contact')" class="u-mt-4">
                <div class="grid-fields">
                    <x-ui.input name="email" type="email" required ltr inputmode="email"
                        :label="__('auth.shared.email')" :value="old('email')"
                        :hint="__('admin.users.email_hint')" />
                    <x-ui.input name="phone" type="tel" required ltr inputmode="tel"
                        :label="__('auth.register.phone')" :value="old('phone')" />
                    <x-ui.select name="gender" required
                        :label="__('auth.register.gender')" :options="$genderOptions" :value="old('gender')" />
                </div>
            </x-ui.card>

            <x-ui.card icon="users" :title="__('admin.users.section_placement')" class="u-mt-4">
                <div class="grid-fields">
                    <x-ui.select name="role" required
                        :label="__('admin.users.table.role')" :options="$roleOptions"
                        :value="old('role', 'participant')" />
                    <x-ui.select name="cohort_id"
                        :label="__('admin.users.cohort')" :options="$cohortOptions"
                        :value="old('cohort_id')"
                        :hint="__('admin.users.cohort_hint')" />
                </div>

                {{-- The account is created active and already verified: the
                     invitation IS the proof of the address, because the only way
                     to learn the password is to have received the letter sent to
                     it. Carried as a field rather than decided in the controller
                     so the choice stays visible in the request that made it. --}}
                <input type="hidden" name="status" value="active">
            </x-ui.card>

            {{-- `.form__submit` and `.form__note` are the shell's own classes.
                 `.prose` would have been wrong here: it lives in public.css,
                 which the dashboard layout does not load, so the paragraph
                 would have carried a class that resolves to nothing. --}}
            <div class="form__submit">
                <x-ui.button variant="primary" type="submit">{{ __('admin.users.invite_submit') }}</x-ui.button>
                <x-ui.button variant="secondary" :href="route('admin.users.index')">{{ __('app.cancel') }}</x-ui.button>
            </div>

            <p class="form__note">{{ __('admin.users.invite_note') }}</p>
        </form>
    @endif
@endsection
