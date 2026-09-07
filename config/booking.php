<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Abandoned draft retention
    |--------------------------------------------------------------------------
    |
    | How many days an untouched booking draft is kept before
    | `booking:purge-stale-drafts` deletes it. Measured against `updated_at`,
    | so a draft still being worked on is never caught by the sweep.
    |
    | From Step 6 a draft holds customer and deceased personal data, so this
    | window is a privacy control, not a housekeeping convenience: it bounds
    | how long that data survives an abandoned booking. Shorten it freely;
    | lengthening it needs a reason.
    |
    */

    'draft_retention_days' => (int) env('BOOKING_DRAFT_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Plot picker bounds (PERF-03)
    |--------------------------------------------------------------------------
    |
    | `BookingWizard::pickerBlocks()` re-runs on every `wire:poll.5s` tick
    | while Step 2's plot picker is open, and `BasePlotFloorMapPage::blocks()`
    | (the admin/vendor floor-map pages built on it) re-runs on every panel
    | render. Both eager-load every block and every plot of the selected
    | cemetery with no bound; for a large granular cemetery that is an
    | unbounded, repeatedly re-executed query. These limits cap both queries
    | the same way `PlotAvailabilityPreview` already bounds its own
    | showcase query, while staying generous enough that a real cemetery's
    | full block/plot inventory still renders in one page for the maps that
    | need every plot.
    |
    */

    'plot_picker_max_blocks' => (int) env('BOOKING_PLOT_PICKER_MAX_BLOCKS', 200),

    'plot_picker_max_plots_per_block' => (int) env('BOOKING_PLOT_PICKER_MAX_PLOTS_PER_BLOCK', 500),

];
