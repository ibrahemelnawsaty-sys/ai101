{{--
    Terms and conditions.
    Public, indexable, linked from the footer and from the registration consent checkbox.

    @see BR-31 · PRD §9.2.1 · Constitution art. 17

    Variables from App\Http\Controllers\Public\LegalController@terms:
      $document  array   structured document (see partials/legal-document.blade.php)
      $state     string  'ok' | 'loading' | 'empty' | 'error'
--}}
@extends('layouts.public')

@section('content')

    <section class="sec sec--legal">
        <div class="wrap wrap--reading">
            @include('partials.legal-document', ['documentTitle' => __('landing.legal.terms_title')])
        </div>
    </section>
@endsection
