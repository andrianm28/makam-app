<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Concerns;

use App\Domain\ServiceCatalog\Models\PriceVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The read half of the versioned-price mechanism: a model that carries prices
 * in the shared `price_versions` table.
 *
 * ---------------------------------------------------------------------------
 * Why a trait rather than a second copy of two methods
 * ---------------------------------------------------------------------------
 * `Models\ServiceDefinition` has carried exactly these two methods since the
 * price-versioning table was created. `2026_07_26_180400_create_price_versions
 * _table.php`'s own doc block anticipated a second priceable — "a
 * package-priceable caller may create rows directly the same way" — and this
 * is that caller arriving. Copying the pair a second time is the hand-
 * maintained duplication `AGENTS.md` §Documentation forbids, and it is the
 * kind that drifts silently: one copy learns about `superseded_at` semantics
 * and the other does not.
 *
 * `ServiceDefinition` now uses this trait and its own copies are gone. The
 * method bodies are unchanged, so its 29 existing test methods prove the
 * extraction preserved behaviour rather than merely not breaking compilation.
 *
 * ---------------------------------------------------------------------------
 * What "current" means, precisely
 * ---------------------------------------------------------------------------
 * A priceable has at most one row with `superseded_at IS NULL` — enforced by
 * `Actions\RecordPriceVersion` stamping the incumbent inside the same
 * transaction that inserts its replacement. `currentPriceVersion()` still
 * orders by `version_number` descending rather than trusting that invariant
 * blindly: if a bug or a manual repair ever left two open rows, the newest
 * wins deterministically instead of whichever the database happened to return
 * first.
 *
 * A priceable with no price at all returns `null`. That is a real state, not
 * an error — a cemetery package exists in the catalogue before anyone has
 * priced it, and every caller must decide for itself whether an unpriced
 * record is something it can proceed with. `ComposeQuoteLinesFromBookingDraft`
 * is the precedent: it throws `UnpricedBookingServiceException` rather than
 * treating a missing price as zero.
 */
trait HasVersionedPrice
{
    /** @return MorphMany<PriceVersion, covariant Model> */
    public function priceVersions(): MorphMany
    {
        return $this->morphMany(PriceVersion::class, 'priceable');
    }

    public function currentPriceVersion(): ?PriceVersion
    {
        return $this->priceVersions()->whereNull('superseded_at')->orderByDesc('version_number')->first();
    }
}
