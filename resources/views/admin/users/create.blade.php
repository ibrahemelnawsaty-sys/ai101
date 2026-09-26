{{--
    Admin · invite one person.

    WHAT IT ASKS FOR, AND WHY IT IS TWO THINGS (D-85)
    A name in Arabic and an address. It used to ask for eleven facts — the
    Latin name four times over, the mobile number, the gender — before an
    invitation could be sent at all, and an administrator holding a list of
    names and addresses could not answer it. Everything else is known best by
    the person themself, who fills it in behind their invitation link.

    Only the first part of the name is required: a two- or three-part name is a
    name, and refusing one sends the administrator hunting for a grandfather's
    name nobody has.

    NO PASSWORD FIELD, DELIBERATELY — and now none is generated either. The
    letter carries a single-use link, and the password is chosen on the other
    side of it by the person it belongs to.

    THE COHORT IS THE FIELD THAT MATTERS. Without it the account is created
    outside every cohort — no assignments, no sessions, no card, no certificate
    path — which is exactly what this form used to produce.

    The field labels are the registration form's own keys, not new ones: the
    two forms ask for the same facts, and a second set of labels is a second
    thing to keep in step (Article 6).

    Four states: error · loading · empty (no cohort exists yet) · normal.

    @see PRD §4.5.1, §9.2.1 · BR-22, BR-32, BR-34 · D-63, D-85
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
            :action-label="__('admin.users.back_to_list')" :action-href="route('admin.users.index')" />
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
                    <p class="form__note">{{ __('admin.users.name_hint') }}</p>
                    <div class="grid-fields">
                        <x-ui.input name="first_name_ar" required maxlength="20"
                            :label="__('auth.register.first_name_ar')" :value="old('first_name_ar')"
                            :error="$errors->first('first_name_ar')" />
                        <x-ui.input name="father_name_ar" maxlength="20"
                            :label="__('auth.register.father_name_ar')" :value="old('father_name_ar')"
                            :error="$errors->first('father_name_ar')" />
                        <x-ui.input name="grandfather_name_ar" maxlength="20"
                            :label="__('auth.register.grandfather_name_ar')" :value="old('grandfather_name_ar')"
                            :error="$errors->first('grandfather_name_ar')" />
                        <x-ui.input name="family_name_ar" maxlength="20"
                            :label="__('auth.register.family_name_ar')" :value="old('family_name_ar')"
                            :error="$errors->first('family_name_ar')" />
                    </div>
                </fieldset>
            </x-ui.card>

            <x-ui.card icon="mail" :title="__('admin.users.section_contact')" class="u-mt-4">
                <div class="grid-fields">
                    <x-ui.input name="email" type="email" required ltr inputmode="email"
                        :label="__('auth.shared.email')" :value="old('email')"
                        :hint="__('admin.users.email_hint')"
                        :error="$errors->first('email')" />
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

                {{-- The account is created active but UNVERIFIED: the link in
                     the letter is what proves the address, and until it is
                     followed the account cannot sign in at all. Carried as a
                     field rather than decided in the controller so the choice
                     stays visible in the request that made it. --}}
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
