{{--
    Loading skeleton — My journey

    Article 17 does not accept a spinner: the loading state is drawn in the
    SHAPE of the content that is coming. This one stands in for
    the progress summary above the ten-step rail, then the rail itself.

    It is also rendered on its own by renderSkeleton() in tests/Pest.php, so it
    reads no variable, touches no model and calls no clock. Every silhouette is
    aria-hidden except the first, which announces "loading" once rather than
    once per block (Article 18). The shimmer stops entirely under
    prefers-reduced-motion, in components.css.

    @see PRD §9.7 · CONSTITUTION Articles 17, 18
--}}
<div class="ui-sk-stack" data-state="loading" aria-busy="true">

    <div class="skel-grid" data-skeleton-block="summary">
        <x-ui.skeleton shape="stat" :count="2" :label="__('ui.skeleton.label')" />
    </div>

    <div data-skeleton-block="progress">
        <x-ui.skeleton shape="bar" aria-hidden="true" />
    </div>

    <div data-skeleton-block="rail">
        <x-ui.skeleton shape="timeline" :count="10" aria-hidden="true" />
    </div>
</div>
