<?php

declare(strict_types=1);

namespace App\Livewire\Public\Home;

use RuntimeException;

/**
 * The homepage plot-availability preview is configured, and resolves to
 * nothing.
 *
 * ---------------------------------------------------------------------------
 * Not thrown — reported
 * ---------------------------------------------------------------------------
 * This never propagates to a visitor. `PlotAvailabilityPreview::render()`
 * passes it to `report()` and carries on rendering the section as absent,
 * which is the correct page either way. Its only job is to make a silent
 * failure audible, through the same `withExceptions()` pipeline every other
 * operational signal in this application already uses.
 *
 * ---------------------------------------------------------------------------
 * Why a dedicated type rather than a log line
 * ---------------------------------------------------------------------------
 * `SpineDegradedException` set the precedent: a named exception class is what
 * an error tracker groups, counts, and alerts on. A `Log::warning()` string
 * is something a person has to already be looking for.
 *
 * ---------------------------------------------------------------------------
 * The distinction this class exists to draw
 * ---------------------------------------------------------------------------
 * An EMPTY config means the feature is deliberately off, and says nothing.
 * A POPULATED config that yields no cemeteries means one of three things,
 * none of them intended:
 *
 *   - no published cemetery carries that slug
 *   - the cemetery exists but is not `PlotTrackingMode::GRANULAR`
 *   - it is granular but has no blocks
 *
 * `buildShowcase()` drops all three with `continue`, so they arrive here
 * indistinguishable. The message names the slugs rather than the cause,
 * because the slugs are what an operator can act on — and checking which of
 * the three it is takes one query once you know which slug to ask about.
 */
final class PlotPreviewMisconfiguredException extends RuntimeException
{
    /**
     * @param  list<string>  $slugs  the configured slugs, verbatim
     */
    public function __construct(public readonly array $slugs)
    {
        parent::__construct(sprintf(
            'Homepage plot preview is configured with [%s] but resolved to no cemeteries. '
            .'Each slug either names no published cemetery, names one that is not granular-tier, '
            .'or names one with no blocks. The section renders as absent, which hides the '
            .'homepage\'s only real-data claim. Fix HOMEPAGE_PLOT_PREVIEW_CEMETERY_SLUGS, or '
            .'clear it to switch the section off deliberately.',
            implode(', ', $slugs),
        ));
    }
}
