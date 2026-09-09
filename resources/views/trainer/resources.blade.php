{{--
    Trainer resources — upload, edit metadata, reorder, archive.
    Download counts are visible to the trainer only (PRD §9.12).
    Uploaded files are stored outside the web root with a random name; the MIME type is
    sniffed from the file CONTENT on the server, never from the extension.

    @see PRD §9.12 · BR-23
--}}
@extends('layouts.app')

@section('title', __('trainer.resources.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('trainer.resources.error_title')"
            :description="__('trainer.resources.error_body')"
            :action-label="__('app.retry')" :action-href="route('trainer.resources')" />
    @else

        {{-- Upload ------------------------------------------------------------ --}}
        <x-ui.card class="dc--span" icon="up" :title="__('trainer.resources.upload_title')">
            {{-- A plain form, for the third time and the same reason (D-54, D-55):
                 atharUploader is called here too and nothing registers it, and
                 x-on:submit.prevent cancelled the native submit before the
                 missing handler could throw. A trainer could not add a single
                 resource to the library. --}}
            <form method="POST" action="{{ route('trainer.resources.store') }}" enctype="multipart/form-data">
                @csrf

                <div class="drop">
                    <div class="drop__ic" aria-hidden="true"><x-ui.icon name="up" /></div>
                    <b>{{ __('assignments.choose_files') }}</b>
                    <span>{{ __('assignments.accepted_types') }}</span>

                    <input type="file" name="files[]" multiple id="resource-files">
                    <label class="sr" for="resource-files">{{ __('assignments.choose_files') }}</label>

                    @error('files')
                        <p class="hint hint--error" role="alert">{{ $message }}</p>
                    @enderror
                    @error('files.*')
                        <p class="hint hint--error" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="f2">
                    <x-ui.input name="title" required :label="__('trainer.resources.field_title')" />
                    <x-ui.select name="type" required :label="__('resources.filter_type')" :options="$typeOptions" />
                    <x-ui.select name="week_id" :label="__('schedule.filter_week')" :options="$weekOptions"
                        :hint="__('trainer.resources.week_hint')" />
                    <x-ui.select name="session_id" :label="__('trainer.resources.link_session')" :options="$sessionOptions" />
                </div>

                <x-ui.input name="external_url" type="url" dir="ltr"
                    :label="__('trainer.resources.external_url')"
                    :hint="__('trainer.resources.external_url_hint')" />

                <x-ui.textarea name="description" rows="3" :label="__('trainer.resources.field_description')" />

                <div class="row__acts">
                    <x-ui.button variant="primary" type="submit"
                        x-bind:aria-busy="uploading">{{ __('trainer.resources.publish') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        {{-- Existing resources -------------------------------------------------- --}}
        <form method="GET" action="{{ route('trainer.resources') }}" class="toolbar u-mt-4">
            <x-ui.search-input name="q" :value="request('q')" :placeholder="__('resources.search_placeholder')" />
            <x-ui.select name="week" :label="__('schedule.filter_week')" :options="$weekOptions" :value="request('week')" />
            <x-ui.select name="state" :label="__('trainer.resources.filter_state')" :options="$stateOptions" :value="request('state')" />
            <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
        </form>

        <x-ui.card class="dc--span" flush>
            @if (is_null($resources))
                <div class="tscroll">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 5; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-3)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s18)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s23)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--touch-min)" /></td>
                                    <td><x-ui.skeleton height="var(--s9)" width="var(--d-1)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($resources->isEmpty())
                <x-ui.empty-state icon="folder"
                    :title="__('trainer.resources.empty_title')"
                    :description="__('trainer.resources.empty_body')" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('trainer.resources.title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('trainer.resources.field_title') }}</th>
                                <th scope="col">{{ __('resources.filter_type') }}</th>
                                <th scope="col">{{ __('schedule.filter_week') }}</th>
                                <th scope="col">{{ __('trainer.resources.col_downloads') }}</th>
                                <th scope="col">{{ __('app.status') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($resources as $resource)
                                <tr>
                                    <th scope="row">{{ $resource->title }}</th>
                                    <td>{{ $resource->typeLabel }}</td>
                                    <td>{{ $resource->weekTitle ?? __('resources.general_group') }}</td>
                                    <td class="u-num">{{ $resource->downloadCount }}</td>
                                    <td><x-ui.pill :variant="$resource->stateVariant">{{ $resource->stateLabel }}</x-ui.pill></td>
                                    <td class="u-nowrap">
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('trainer.resources', ['edit' => $resource->id])">{{ __('app.edit') }}</x-ui.button>
                                        {{-- The route is declared DELETE (routes/web.php); the method
                                             spoof must agree or the form 405s. Archiving writes a
                                             deleted_at stamp — nothing is destroyed. --}}
                                        <form method="POST" action="{{ route('trainer.resources.archive', $resource->id) }}" class="u-inline">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button variant="secondary" size="sm" type="submit">
                                                {{ $resource->isArchived ? __('trainer.resources.restore') : __('trainer.resources.archive') }}
                                            </x-ui.button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$resources" />
            @endif
        </x-ui.card>
    @endif
@endsection
