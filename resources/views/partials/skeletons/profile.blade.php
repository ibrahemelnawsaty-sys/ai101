{{--
    Loading skeleton — Profile

    Article 17 does not accept a spinner: the loading state is drawn in the
    SHAPE of the content that is coming. This one stands in for
    the avatar with the name beside it, then the form fields.

    It is also rendered on its own by renderSkeleton() in tests/Pest.php, so it
    reads no variable, touches no model and calls no clock. Every silhouette is
    aria-hidden except the first, which announces "loading" once rather than
    once per block (Article 18). The shimmer stops entirely under
    prefers-reduced-motion, in components.css.

    @see PRD §9.18 · CONSTITUTION Articles 17, 18
--}}
<div class="ui-sk-stack" data-state="loading" aria-busy="true">

    <div class="skel-row" data-skeleton-block="identity">
        <x-ui.skeleton shape="avatar" :label="__('ui.skeleton.label')" />
    </div>

    <div class="skel-form" data-skeleton-block="form">
        <x-ui.skeleton shape="line" :count="8" aria-hidden="true" />
    </div>

    <div class="skel-row" data-skeleton-block="actions">
        <x-ui.skeleton shape="button" :count="2" aria-hidden="true" />
    </div>
</div>
