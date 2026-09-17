<?php

declare(strict_types=1);

namespace App\Domain\Quotation\Exceptions;

use RuntimeException;

/**
 * The selected plot cannot be priced, so no quote may be composed.
 *
 * The sibling of `UnpricedBookingServiceException`, and thrown for the same
 * reason its doc block gives: this feeds a financial WRITE. Silently dropping
 * the plot line would underquote an order by its LARGEST component — the read
 * path may degrade to "harga belum tersedia"; the write path may not.
 *
 * Per ADR-0042 and the Tahap 1 spec, the pricing vehicle is the DRAFT's
 * package, never `grave_plots.cemetery_package_id`, which that column's own
 * migration calls "an indicative convenience reference, not the plot's
 * identity".
 */
final class UnpricedBookingPlotException extends RuntimeException
{
    public static function forMissingPackage(): self
    {
        return new self(
            'The booking draft holds a plot but names no cemetery package, so no price can be '.
            'computed. Selecting a package is a precondition of selecting a plot.'
        );
    }

    public static function forUnpricedPackage(int|string $packageId): self
    {
        return new self(
            "Cemetery package [{$packageId}] has no current firm price version, so the held plot ".
            'cannot be charged. An operator must record a price before this order can be quoted.'
        );
    }

    public static function forMissingPlot(int|string $plotId): self
    {
        return new self(
            "The booking draft holds grave plot [{$plotId}], which no longer exists. The hold and "
            .'the plot have diverged; refusing to quote against a plot that cannot be read.'
        );
    }

    public static function forCrossCemeteryPackage(int|string $packageId): self
    {
        return new self(
            "Cemetery package [{$packageId}] belongs to a different cemetery than the held plot; ".
            'refusing to price one cemetery\'s plot with another\'s package.'
        );
    }
}
