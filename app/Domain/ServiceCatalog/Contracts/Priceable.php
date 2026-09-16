<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Contracts;

use App\Domain\ServiceCatalog\Models\PriceVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A model that carries versioned prices in the shared `price_versions` table.
 *
 * This exists so `Actions\RecordPriceVersion` can be given a TYPE rather than
 * a `method_exists()` check. The first draft of that action took a bare
 * `Model` and guarded at runtime — which meant a caller could pass the wrong
 * model and only find out in production, and PHPStan could not see
 * `priceVersions()` at all. An interface turns both problems into a compile-
 * time one.
 *
 * `Concerns\HasVersionedPrice` provides the implementation; a priceable model
 * implements this and uses that trait.
 *
 * @phpstan-require-extends Model
 */
interface Priceable
{
    /**
     * This model's full price history, newest version number last.
     *
     * @return MorphMany<PriceVersion, covariant Model>
     */
    public function priceVersions(): MorphMany;

    /**
     * The one row with `superseded_at IS NULL`, or `null` when nothing has
     * ever priced this model.
     *
     * Null is a real state, not an error: a catalogue entry exists before
     * anyone has priced it. Every caller must decide for itself whether it can
     * proceed — `ComposeQuoteLinesFromBookingDraft` sets the precedent by
     * throwing `UnpricedBookingServiceException` rather than treating a
     * missing price as zero.
     */
    public function currentPriceVersion(): ?PriceVersion;
}
