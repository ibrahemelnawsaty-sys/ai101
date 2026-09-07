{{--
    Loading skeleton — Digital participant card

    Article 17 does not accept a spinner: the loading state is drawn in the
    SHAPE of the content that is coming. This one stands in for
    the card face with its QR panel, then the identity lines and the verification actions.

    It is also rendered on its own by renderSkeleton() in tests/Pest.php, so it
    reads no variable, touches no model and calls no clock. Every silhouette is
    aria-hidden except the first, which announces "loading" once rather than
    once per block (Article 18). The shimmer stops entirely under
    prefers-reduced-motion, in components.css.

    @see PRD §9.6 · CONSTITUTION Articles 17, 18
--}}
<div class="ui-sk-stack" data-state="loading" aria-busy="true">

    <div data-skeleton-block="face">
        <x-ui.skeleton shape="thumb" :label="__('ui.skeleton.label')" />
    </div>

    <div data-skeleton-block="identity">
        <x-ui.skeleton shape="text" :lines="4" aria-hidden="true" />
    </div>

    <div class="skel-row" data-skeleton-block="actions">
        <x-ui.skeleton shape="button" :count="2" aria-hidden="true" />
    </div>
</div>
