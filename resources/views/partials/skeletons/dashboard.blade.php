{{--
    Loading skeleton — Participant dashboard

    Article 17 does not accept a spinner: the loading state is drawn in the
    SHAPE of the content that is coming. This one stands in for
    the welcome banner, the four-figure strip, and the nine independent cards that make up the board.

    It is also rendered on its own by renderSkeleton() in tests/Pest.php, so it
    reads no variable, touches no model and calls no clock. Every silhouette is
    aria-hidden except the first, which announces "loading" once rather than
    once per block (Article 18). The shimmer stops entirely under
    prefers-reduced-motion, in components.css.

    @see PRD §9.5.3 · CONSTITUTION Articles 17, 18
--}}
<div class="ui-sk-stack" data-state="loading" aria-busy="true">

    <div class="skel-hero" data-skeleton-block="welcome">
        <x-ui.skeleton shape="text" :lines="2" :label="__('ui.skeleton.label')" />
    </div>

    <div class="skel-grid" data-skeleton-block="stats">
        <x-ui.skeleton shape="stat" :count="4" aria-hidden="true" />
    </div>

    <div class="skel-cards" data-skeleton-block="cards">
        <x-ui.skeleton shape="card" :count="6" :lines="3" aria-hidden="true" />
    </div>
</div>
