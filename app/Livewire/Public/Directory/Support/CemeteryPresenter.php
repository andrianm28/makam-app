<?php

declare(strict_types=1);

namespace App\Livewire\Public\Directory\Support;

use App\Domain\CemeteryDirectory\Models\Cemetery;

/**
 * Presentation helpers shared by the directory list card and the detail
 * page — Sprint 4 S4-T6, `.kiro/specs/cemetery-directory-and-availability`
 * AC3 ("display type, name, photo, address, Google Maps/navigation URL,
 * facilities, attributed price range, and availability") and AC11.
 *
 * Exists so the two views cannot drift into two different renderings of the
 * same field. Formatting only — no querying, no capability logic (that is
 * `CemeteryDirectoryQuery` and `CemeteryAvailabilityIntent` respectively).
 */
final class CemeteryPresenter
{
    /**
     * AC3's price range, formatted with Indonesian thousands separators —
     * `Rp 4.000.000 - Rp 7.500.000`. `null` when either bound is missing.
     *
     * A `null` here is a real, expected state, not a bug: the seed
     * migration's own doc block records that `price_min`/`price_max` ship
     * `null` for a cemetery with no agreed pricing, because "showing
     * nothing is honest; showing a number with an invented `price_source`
     * would not be." The views render an explicit "price not yet available"
     * message for that case rather than an empty gap.
     *
     * Callers MUST pair this with `priceSource()` — AC3 requires the range
     * to be ATTRIBUTED, and design-system.md §2.3 requires a named source
     * and last-updated time on any fee figure. `priceAttribution()` below
     * is the single call that returns both, so a view cannot accidentally
     * render the number without its provenance.
     */
    public static function priceRange(Cemetery $cemetery): ?string
    {
        if ($cemetery->price_min === null || $cemetery->price_max === null) {
            return null;
        }

        $currency = (string) ($cemetery->price_currency ?? 'IDR');
        // `IDR` is the only currency this MVP's launch cities transact in
        // and the column defaults to it; `Rp` is its ordinary Indonesian
        // written symbol. Anything else is shown as its raw code rather
        // than guessing at a symbol this batch has no source for.
        $symbol = $currency === 'IDR' ? 'Rp' : $currency;

        $min = number_format((float) $cemetery->price_min, 0, ',', '.');
        $max = number_format((float) $cemetery->price_max, 0, ',', '.');

        return "{$symbol} {$min} - {$symbol} {$max}";
    }

    /**
     * The mandatory attribution for a price range — source string plus the
     * date it was effective, or `null` when no price is shown at all.
     *
     * @return array{source: string, effective: ?string}|null
     */
    public static function priceAttribution(Cemetery $cemetery): ?array
    {
        if (self::priceRange($cemetery) === null) {
            return null;
        }

        $source = $cemetery->price_source;

        return [
            // A price with no recorded source would defeat AC3's whole
            // point, so an absent source is stated as absent rather than
            // quietly omitted — an unattributed figure must never look
            // attributed.
            'source' => $source === null || $source === ''
                ? 'Sumber tidak tercatat'
                : (string) $source,
            'effective' => $cemetery->price_effective_at?->format('d/m/Y'),
        ];
    }

    /**
     * AC3's photo. `primary_photo_path` stores a path relative to
     * `public/` (see `2026_07_26_190000_create_cemeteries_table.php`'s
     * column comment and the backfill migration that populates it), so it
     * is resolved with `asset()`. `null` when the cemetery has no image —
     * the views render a plain placeholder block rather than a broken
     * `<img>`.
     */
    public static function photoUrl(Cemetery $cemetery): ?string
    {
        $path = $cemetery->primary_photo_path;

        if ($path === null || $path === '') {
            return null;
        }

        return asset((string) $path);
    }

    /**
     * AC11: "provide an external navigation link from coordinates/address
     * via Google Maps. WHEN the map provider fails THE SYSTEM SHALL NOT
     * block viewing of the textual address."
     *
     * `Cemetery::googleMapsUrl()` already returns `null` when there is
     * neither an operator-supplied URL nor coordinates to derive one from.
     * This method adds nothing but a name; it exists so the views read as
     * "the map link is a separate, optional thing" rather than appearing to
     * derive the address from the same source. **The address is rendered
     * unconditionally by both views regardless of what this returns** —
     * that is the actual AC11 guarantee, and it lives in the views, not
     * here.
     */
    public static function mapUrl(Cemetery $cemetery): ?string
    {
        return $cemetery->googleMapsUrl();
    }

    /**
     * AC3's facilities, as a plain list of strings. The column is cast to
     * `array` by the model; a `null` or malformed value degrades to an
     * empty list rather than throwing inside a card render.
     *
     * @return list<string>
     */
    public static function facilities(Cemetery $cemetery): array
    {
        $facilities = $cemetery->facilities;

        if (! is_array($facilities)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $facility): string => (string) $facility,
            array_filter($facilities, static fn (mixed $facility): bool => is_scalar($facility)),
        ));
    }
}
