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
| Default: EMPTY — the section is off until an operator points it at a
| cemetery whose plot data is genuinely real.
|
| It used to default to `tpu-petamburan,tpu-karet-bivak`, on the stated
| premise that those were "the two real, published cemeteries ... Neither is
| granular-tier as of this writing". Measured 14 Sep 2026: neither slug
| exists in the dev database OR the public beta's, so the section rendered
| NOTHING on both hosts, and nothing said so. (`2026_09_13_100000_backfill_
| real_photos_by_current_value_not_slug.php` had already recorded the same
| absence from a different direction.)
|
| AN EMPTY DEFAULT IS THE HONEST ONE, and not only because the old one was
| broken. This section's own copy reads "Data plot di bawah ini NYATA dan
| diperbarui secara berkala, bukan ilustrasi". That is a truth claim made to
| a family, so this setting must never name a cemetery whose data is example
| data — which today rules out the only granular-tier, published cemetery on
| either host (`tpu-jakarta-menteng`, address "Jl. Contoh ..."). Rendering
| nothing is correct until that changes; rendering seeded rows under a
| promise of real ones would not be.
|
| A populated setting that resolves to no cemeteries is now REPORTED rather
| than swallowed — see `PlotPreviewMisconfiguredException`. An empty setting
| stays silent, because off-on-purpose is not a defect.
|
*/
return [
    'homepage_plot_preview_cemetery_slugs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'HOMEPAGE_PLOT_PREVIEW_CEMETERY_SLUGS',
            '',
        )),
    ))),
];
