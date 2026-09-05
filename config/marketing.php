<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Homepage plot-availability preview — showcase cemeteries
|--------------------------------------------------------------------------
|
| Slugs of the cemeteries App\Livewire\Public\Home\PlotAvailabilityPreview
| samples for the homepage's live-availability proof point
| (docs/superpowers/specs/2026-09-05-marketing-hero-plot-preview-design.md).
| A cemetery here that is not published, or not yet flipped to
| App\Domain\CemeteryDirectory\PlotTrackingMode::GRANULAR via
| App\Domain\CemeteryDirectory\Actions\SetCemeteryPlotTrackingMode, is
| silently skipped by the component — this list is a wishlist of curated
| candidates, not a guarantee every entry renders.
|
| Config, not a class constant, so which cemeteries are showcased can
| change without a deploy-and-decide cycle — the same reasoning
| config/plot-reservation.php's draft_hold_ttl_minutes gives for the same
| choice.
|
| Default: the two real, published cemeteries with real photography
| already backed in via 2026_08_24_100000_backfill_photo_and_maps_url_for_
| real_cemeteries.php. Neither is granular-tier as of this writing — the
| section renders its honest empty state against today's real database
| until an operator provisions block/plot data for one of them.
|
*/
return [
    'homepage_plot_preview_cemetery_slugs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'HOMEPAGE_PLOT_PREVIEW_CEMETERY_SLUGS',
            'tpu-petamburan,tpu-karet-bivak',
        )),
    ))),
];
