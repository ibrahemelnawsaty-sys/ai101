{{--
    Loading skeleton — Programme schedule

    Article 17 does not accept a spinner: the loading state is drawn in the
    SHAPE of the content that is coming. This one stands in for
    the week tabs, then the session rows of the selected week.

    It is also rendered on its own by renderSkeleton() in tests/Pest.php, so it
    reads no variable, touches no model and calls no clock. Every silhouette is
    aria-hidden except the first, which announces "loading" once rather than
    once per block (Article 18). The shimmer stops entirely under
    prefers-reduced-motion, in components.css.

    @see PRD §9.8 · CONSTITUTION Articles 17, 18
--}}
<div class="ui-sk-stack" data-state="loading" aria-busy="true">

    <div class="skel-row" data-skeleton-block="filters">
        <x-ui.skeleton shape="chip" :count="4" :label="__('ui.skeleton.label')" />
    </div>

    <div data-skeleton-block="sessions">
        <x-ui.skeleton shape="row" :count="6" aria-hidden="true" />
    </div>
</div>
