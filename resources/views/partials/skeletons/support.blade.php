{{--
    Loading skeleton — Support (D-124)

    Article 17 does not accept a spinner: the loading state is drawn in the
    SHAPE of the content that is coming — the tabs and the ticket rows, each a
    subject line, its number and its status.

    It is also rendered on its own by renderSkeleton() in tests/Pest.php, so it
    reads no variable, touches no model and calls no clock. Only the first
    silhouette announces "loading" (Article 18); the shimmer stops entirely
    under prefers-reduced-motion, in components.css.

    @see D-124 · CONSTITUTION Articles 17, 18
--}}
<div class="ui-sk-stack" data-state="loading" aria-busy="true">

    <div data-skeleton-block="tabs">
        <x-ui.skeleton shape="bar" :label="__('ui.skeleton.label')" />
    </div>

    <div data-skeleton-block="tickets">
        <x-ui.skeleton shape="row" :count="6" aria-hidden="true" />
    </div>
</div>
