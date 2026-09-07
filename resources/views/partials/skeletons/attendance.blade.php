{{--
    Loading skeleton — Attendance record

    Article 17 does not accept a spinner: the loading state is drawn in the
    SHAPE of the content that is coming. This one stands in for
    the three attendance figures, the check-in card, then the per-session table.

    It is also rendered on its own by renderSkeleton() in tests/Pest.php, so it
    reads no variable, touches no model and calls no clock. Every silhouette is
    aria-hidden except the first, which announces "loading" once rather than
    once per block (Article 18). The shimmer stops entirely under
    prefers-reduced-motion, in components.css.

    @see PRD §9.9 · CONSTITUTION Articles 17, 18
--}}
<div class="ui-sk-stack" data-state="loading" aria-busy="true">

    <div class="skel-grid" data-skeleton-block="stats">
        <x-ui.skeleton shape="stat" :count="3" :label="__('ui.skeleton.label')" />
    </div>

    <div data-skeleton-block="window">
        <x-ui.skeleton shape="card" :count="1" :lines="2" aria-hidden="true" />
    </div>

    <div data-skeleton-block="table">
        <x-ui.skeleton shape="table" :count="6" :columns="5" aria-hidden="true" />
    </div>
</div>
