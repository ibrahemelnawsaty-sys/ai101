{{--
    Loading skeleton — Messages

    Article 17 does not accept a spinner: the loading state is drawn in the
    SHAPE of the content that is coming. This one stands in for
    the thread list on one side and the open conversation on the other.

    It is also rendered on its own by renderSkeleton() in tests/Pest.php, so it
    reads no variable, touches no model and calls no clock. Every silhouette is
    aria-hidden except the first, which announces "loading" once rather than
    once per block (Article 18). The shimmer stops entirely under
    prefers-reduced-motion, in components.css.

    @see PRD §9.14 · CONSTITUTION Articles 17, 18
--}}
<div class="ui-sk-stack" data-state="loading" aria-busy="true">

    <div data-skeleton-block="threads">
        <x-ui.skeleton shape="row" :count="5" :label="__('ui.skeleton.label')" />
    </div>

    <div data-skeleton-block="conversation">
        <x-ui.skeleton shape="text" :lines="6" aria-hidden="true" />
    </div>

    <div data-skeleton-block="composer">
        <x-ui.skeleton shape="bar" aria-hidden="true" />
    </div>
</div>
