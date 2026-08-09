<?php

declare(strict_types=1);

namespace App\Livewire\Public\Marketplace\Support;

use App\Domain\Marketplace\Models\Product;

/**
 * Presentation helpers shared by the marketplace list card (PUB-020) and the
 * product detail page (PUB-021).
 *
 * Exists for one reason: `products.vendor_name` and `products.base_price_idr`
 * hold clearly-fictional dummy values
 * (`2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products.php`'s
 * doc block: *"None of the following is real"*), and until this class existed
 * both views rendered them bare — no source, no marker — on a public host,
 * beside copy telling the visitor to phone customer service to order the
 * product now. A visitor could not tell.
 *
 * Two rules this class makes structural rather than conventional:
 *
 *   1. `vendor_name` is never rendered without the fabricated-data marker.
 *      That marker is this repository's established convention, not a new
 *      invention — `2026_08_08_100010_seed_example_grave_records.php:28-31`
 *      names the literal word "Contoh" as *"this repository's established
 *      fabricated-data marker"*, and gives the reasoning that applies here
 *      almost word for word: the marker belongs on the NAME itself, not only
 *      in a doc comment, because a fabricated but plausible-looking name
 *      could be mistaken for a real one by anyone who does not read the
 *      migration.
 *   2. `base_price_idr` is never rendered without an attribution line.
 *      `docs/design/design-system.md:214` §2.3 states as a **DO**: *"Show the
 *      source and last-updated time on any fee or availability figure."*
 *      `CemeteryPresenter::priceAttribution()` is the sibling implementation
 *      of the same rule, and `PRICE_SOURCE` below is the string the cemetery
 *      price backfill already uses for the identical situation.
 *
 * `priceAttribution()` returns the figure and its source together, in one
 * call, so a view cannot accidentally render the number without its
 * provenance — the same reason `CemeteryPresenter` is shaped that way.
 *
 * **This closes the user-facing half only.** The seeded column values
 * themselves still read as real to anyone querying the database directly;
 * marking them at the data layer needs a migration on a public-facing,
 * financial-adjacent table, which `AGENTS.md` §Infrastructure-agent execution
 * makes a human gate. That half is ledgered in `retrofit-backlog.md` §2, not
 * closed here.
 */
final class MarketplacePresenter
{
    /**
     * The attribution shown beside every rendered price. Copied verbatim
     * from what the cemetery price backfill writes into `price_source`
     * (`2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php:219`)
     * rather than newly worded, so the two public surfaces say the same
     * thing about the same class of data.
     */
    public const PRICE_SOURCE = 'Estimasi internal (data contoh)';

    /**
     * The marker appended to every rendered vendor name. Lower-case
     * "contoh" matches the parenthetical form `PRICE_SOURCE` uses; the
     * grave-record seed's capitalised "Contoh" prefix is the same
     * convention applied to a value that carries its marker in the column.
     */
    public const VENDOR_MARKER = '(vendor contoh)';

    /**
     * The vendor name as it may be shown to a visitor — always carrying the
     * fabricated-data marker. `null` when no vendor is recorded, which the
     * views render as nothing at all rather than as an empty marker.
     */
    public static function vendorLabel(Product $product): ?string
    {
        $vendor = $product->vendor_name;

        if ($vendor === null || trim($vendor) === '') {
            return null;
        }

        return trim($vendor).' '.self::VENDOR_MARKER;
    }

    /**
     * The formatted price and its mandatory source, or `null` when the
     * product has no price. A `null` here is a real, expected state — the
     * views render an explicit "harga belum tersedia" message for it, which
     * is honest and needs no attribution because no figure is shown.
     *
     * @return array{amount: string, source: string}|null
     */
    public static function priceAttribution(Product $product): ?array
    {
        if ($product->base_price_idr === null) {
            return null;
        }

        return [
            'amount' => 'Rp '.number_format((float) $product->base_price_idr, 0, ',', '.'),
            'source' => self::PRICE_SOURCE,
        ];
    }
}
