{{--
    Admin · Roles and permissions (D-133).

    An EXPLANATION, read-only by construction: no form, no control that changes
    anything. Who may do what is not written here — the presenter reads it from the
    router's own `role:` middleware — and the words come from lang/roles.php. The
    server still decides every request (art. 5); this page is never a source of a
    permission.

    A static page has no data to load, to find empty or to fail on, so the four
    data states of art. 17 do not apply; the controller passes `errorState` only to
    keep the shell's contract.

    "Yes" and "no" are written for a screen reader in every cell, and drawn as a
    tick or a dash — never colour alone (art. 18).

    @see D-133, D-117 · PRD §4 · CONSTITUTION Articles 5, 17, 18
--}}
@extends('layouts.app')

@section('title', __('roles.title'))
@section('subtitle', __('roles.subtitle'))

@section('content')
    <div class="note">
        <x-ui.icon name="info" />
        {{ __('roles.how_to_change') }}
    </div>

    <h2 class="abrief__sub u-mt-4" id="roles-h">{{ __('roles.roles_title') }}</h2>

    <div class="rolegrid" role="list" aria-labelledby="roles-h">
        @foreach ($overview->roles as $role)
            <x-ui.card role="listitem" icon="roles" :title="$role['label']">
                <p>{{ $role['summary'] }}</p>
                <p class="role__scope"><b>{{ __('roles.scope_label') }}:</b> {{ $role['scope'] }}</p>
            </x-ui.card>
        @endforeach
    </div>

    <h2 class="abrief__sub u-mt-4" id="matrix-h">{{ __('roles.matrix_title') }}</h2>
    <p class="hint">{{ __('roles.matrix_intro') }}</p>

    <div class="tscroll u-mt-2">
        <table class="atable">
            <caption class="sr">{{ __('roles.matrix_title') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('roles.matrix.capability') }}</th>
                    @foreach ($overview->roles as $role)
                        <th scope="col">{{ $role['label'] }}</th>
                    @endforeach
                </tr>
            </thead>

            @foreach ($overview->areas as $area)
                <tbody>
                    <tr class="atable__group">
                        <th scope="colgroup" colspan="{{ count($overview->roles) + 1 }}">{{ $area['label'] }}</th>
                    </tr>
                    @foreach ($area['rows'] as $row)
                        <tr>
                            <th scope="row" class="atable__cap">{{ $row['text'] }}</th>
                            @foreach ($row['cells'] as $allowed)
                                <td class="atable__cell">
                                    @if ($allowed)
                                        <x-ui.icon name="check" /><span class="sr">{{ __('roles.matrix.yes') }}</span>
                                    @else
                                        <span aria-hidden="true">—</span><span class="sr">{{ __('roles.matrix.no') }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            @endforeach
        </table>
    </div>
@endsection
