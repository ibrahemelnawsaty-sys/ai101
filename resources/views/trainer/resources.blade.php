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
        <x-ui.card class="dc--span" icon="upload" :title="__('trainer.resources.upload_title')">
            {{-- A plain form, for the third time and the same reason (D-54, D-55):
                 atharUploader is called here too and nothing registers it, and
                 x-on:submit.prevent cancelled the native submit before the
                 missing handler could throw. A trainer could not add a single
                 resource to the library. The fourth break was quieter: this
                 form posted `type`/`external_url`/`files[]`, but the server
                 reads `resource_type`/`url`/`file` — every name below is kept
                 identical to App\Http\Requests\Trainer\StoreResourceRequest. --}}
            <form method="POST" action="{{ route('trainer.resources.store') }}" enctype="multipart/form-data">
                @csrf

                <div class="f2">
                    <x-ui.input name="title" required :label="__('trainer.resources.field_title')" />
                    <x-ui.select name="resource_type" required :label="__('resources.filter_type')" :options="$typeOptions" />
                    <x-ui.select clearable :placeholder="__('trainer.resources.no_week')" name="week_id" :label="__('schedule.filter_week')" :options="$weekOptions"
                        :hint="__('trainer.resources.week_hint')" />
                    <x-ui.select clearable :placeholder="__('trainer.resources.no_link_session')" name="session_id" :label="__('trainer.resources.link_session')" :options="$sessionOptions" />
                </div>

                <x-ui.file-uploader name="file"
                    :label="__('trainer.resources.field_file')"
                    :accept="$uploadAccept"
                    :max-mb="$maxFileSizeLabel" />

                <x-ui.input name="url" type="url" dir="ltr"
                    :label="__('trainer.resources.external_url')"
                    :hint="__('trainer.resources.external_url_hint')" />

                <x-ui.textarea name="description" rows="3" :label="__('trainer.resources.field_description')" />

                <div class="row__acts">
                    {{-- `uploading` belonged to the retired uploader component and
                         threw on every render (D-86). --}}
                    <x-ui.button icon="upload" variant="primary" type="submit">{{ __('trainer.resources.publish') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        {{-- Existing resources -------------------------------------------------- --}}
        <form method="GET" action="{{ route('trainer.resources') }}" class="toolbar u-mt-4">
            <x-ui.search-input name="q" :value="request('q')" :placeholder="__('resources.search_placeholder')" />
            <x-ui.select clearable name="week" :label="__('schedule.filter_week')" :options="$weekOptions" :value="request('week')" />
            <x-ui.select clearable name="state" :label="__('trainer.resources.filter_state')" :options="$stateOptions" :value="request('state')" />
            <x-ui.button icon="filter" variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
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
                    <table class="atable atable--stack" role="table">
                        <caption class="sr">{{ __('trainer.resources.title') }}</caption>
                        <thead role="rowgroup">
                            <tr role="row">
                                <th scope="col" role="columnheader">{{ __('trainer.resources.field_title') }}</th>
                                <th scope="col" role="columnheader">{{ __('resources.filter_type') }}</th>
                                <th scope="col" role="columnheader">{{ __('schedule.filter_week') }}</th>
                                <th scope="col" role="columnheader">{{ __('trainer.resources.col_downloads') }}</th>
                                <th scope="col" role="columnheader">{{ __('app.status') }}</th>
                                <th scope="col" role="columnheader"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody role="rowgroup">
                            @foreach ($resources as $resource)
                                <tr role="row">
                                    <th scope="row" role="rowheader" class="atable__lead">{{ $resource->title }}</th>
                                    <td role="cell" data-label="{{ __('resources.filter_type') }}">{{ $resource->typeLabel }}</td>
                                    <td role="cell" data-label="{{ __('schedule.filter_week') }}">{{ $resource->weekTitle ?? __('resources.general_group') }}</td>
                                    <td role="cell" data-label="{{ __('trainer.resources.col_downloads') }}"><span class="u-num">{{ $resource->downloadCount }}</span></td>
                                    <td role="cell" data-label="{{ __('app.status') }}"><x-ui.pill :variant="$resource->stateVariant">{{ $resource->stateLabel }}</x-ui.pill></td>
                                    <td role="cell" class="u-nowrap">
                                        <x-ui.button icon="pencil" :icon-only="true" variant="secondary" size="sm" :context="$resource->title"
                                            :href="route('trainer.resources', $carriedQuery + ['edit' => $resource->id])">{{ __('app.edit') }}</x-ui.button>
                                        {{-- The route is declared DELETE (routes/web.php); the method
                                             spoof must agree or the form 405s. Archiving writes a
                                             deleted_at stamp — nothing is destroyed. --}}
                                        <form method="POST" action="{{ route('trainer.resources.archive', $resource->id) }}" class="u-inline">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button variant="secondary" size="sm" type="submit"
                                                :icon="$resource->isArchived ? 'undo' : 'archive'" :icon-only="true" :context="$resource->title">
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

        {{-- The edit drawer (D-136): open because the server rendered it so, from
             `?edit={id}`; every way out is a link back to the list, so closing works
             with scripting off too. A wrong entry re-renders it open, with its
             errors and what was typed. Data only: the file, the type, the size and
             the download count are not in this form. --}}
        @if ($editing)
            <x-ui.drawer name="resource-edit" :title="__('trainer.resources.edit_title')" :open="true" :close-href="$closeHref">
                <form method="POST" action="{{ route('trainer.resources.update', ['resource' => $editing->id] + $carriedQuery) }}">
                    @csrf
                    @method('PATCH')

                    <p class="footnote">{{ __('trainer.resources.edit_intro') }}</p>
                    <p class="u-mb-4"><x-ui.pill variant="neutral" icon="file">{{ __('trainer.resources.edit_type', ['type' => $editing->typeLabel]) }}</x-ui.pill></p>

                    <x-ui.input name="title" required :label="__('trainer.resources.field_title')"
                        :value="old('title', $editing->title)" />

                    <x-ui.textarea name="description" rows="3" :label="__('trainer.resources.field_description')"
                        :value="old('description', $editing->description)" />

                    <x-ui.select clearable :placeholder="__('trainer.resources.no_week')" name="week_id" :label="__('schedule.filter_week')" :options="$weekOptions"
                        :hint="__('trainer.resources.week_hint')" :value="old('week_id', $editing->weekId)" />

                    <x-ui.select clearable :placeholder="__('trainer.resources.no_link_session')" name="session_id" :label="__('trainer.resources.link_session')" :options="$sessionOptions"
                        :value="old('session_id', $editing->sessionId)" />

                    @if ($editing->hasAddress)
                        <x-ui.input name="url" type="url" dir="ltr" required
                            :label="__('trainer.resources.external_url')"
                            :value="old('url', $editing->url)" />
                    @endif

                    <div class="row__acts">
                        <x-ui.button icon="check" variant="primary" type="submit">{{ __('trainer.resources.save_changes') }}</x-ui.button>
                        <x-ui.button variant="ghost" :href="$closeHref">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.drawer>
        @endif
    @endif
@endsection
