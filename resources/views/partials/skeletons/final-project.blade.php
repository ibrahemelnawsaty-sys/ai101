{{--
    Loading skeleton — Final project

    Article 17 does not accept a spinner: the loading state is drawn in the
    SHAPE of the content that is coming. This one stands in for
    the brief, the upload panel, then the submission history.

    It is also rendered on its own by renderSkeleton() in tests/Pest.php, so it
    reads no variable, touches no model and calls no clock. Every silhouette is
    aria-hidden except the first, which announces "loading" once rather than
    once per block (Article 18). The shimmer stops entirely under
    prefers-reduced-motion, in components.css.

    @see PRD §9.12 · CONSTITUTION Articles 17, 18
--}}
<div class="ui-sk-stack" data-state="loading" aria-busy="true">

    <div class="skel-hero" data-skeleton-block="brief">
        <x-ui.skeleton shape="text" :lines="4" :label="__('ui.skeleton.label')" />
    </div>

    <div data-skeleton-block="uploader">
        <x-ui.skeleton shape="thumb" aria-hidden="true" />
    </div>

    <div data-skeleton-block="history">
        <x-ui.skeleton shape="row" :count="2" aria-hidden="true" />
    </div>
</div>
