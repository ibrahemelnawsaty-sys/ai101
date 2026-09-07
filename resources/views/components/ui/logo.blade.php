{{--
    Logo

    The Athar wordmark and mark, inlined. The paths are the exact outlines
    extracted from ATHAR.pdf and kept in brand/svg/ and brand/icons/; inlining
    them costs no network request and lets the logo inherit `currentColor`, so
    one file serves every tone (Article 19).

    The mark is for small sizes only, where the wordmark would be unreadable.

    The paths are written out here rather than pulled from a <use> reference, so
    the logo renders on a page that carries no icon sprite at all - the error
    pages, a printed certificate, a bare verification view.

    Colour comes from the tone classes in components.css — never from an inline
    literal (Article 13 rule 4). Gold and yellow do not exist here in any tone
    and never will (Article 14).

    @see PRD §5.1 · CONSTITUTION Articles 13, 14, 19 · PROJECT-CONTRACT §1, §12

    Props
      variant   wordmark | mark
      size      sm | md | lg | xl
      state     default            the logo has one state
      tone      brand | light | ink | teal | current
      href      wrap the logo in a link (usually the home route)
      tagline   print the platform tagline beside the mark
--}}


<{{ $tag }}
    @if ($href !== null) href="{{ $href }}" aria-label="{{ __('ui.logo.home') }}" @endif
    {{ $attributes->class([
        'ui-logo',
        'ui-logo--' . $size => $size !== 'md',
        'ui-logo--' . $tone => $tone !== 'brand',
    ]) }}
>
    <svg viewBox="{{ $viewBox }}" fill="currentColor" role="img" aria-labelledby="{{ $titleId }}" focusable="false">
        <title id="{{ $titleId }}">{{ $alt }}</title>
        <path fill="currentColor" d="{{ $path }}"/>
    </svg>

    @if ($tagline !== null)
        <span class="ui-logo__tagline">{{ $tagline }}</span>
    @endif
</{{ $tag }}>
