<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use InvalidArgumentException;

/**
 * The closed list of `products.category` values — and the resolution taken
 * for a real, spec-acknowledged open question. Read this doc block before
 * touching `products.category` anywhere.
 *
 * ---------------------------------------------------------------------------
 * OPEN QUESTION this class resolves (narrowly) — category codes are
 * genuinely undefined in the canonical catalogue
 * ---------------------------------------------------------------------------
 * `.kiro/specs/funeral-marketplace-and-vendor-portal/tasks.md` §"⚠️ OPEN
 * QUESTION — category identifiers are undefined" is explicit:
 * `docs/product/marketplace-catalog.md` defines nine product codes but ZERO
 * category codes — Karangan Bunga / Batu Nisan / Perawatan Makam exist only
 * as Markdown headings (`### Karangan Bunga`, etc.), never as a coded
 * identifier anywhere. That same section is equally explicit that INVENTING
 * a category code/slug here would be "a second, competing source of truth —
 * the exact defect this section exists to fix," and that category ROUTING
 * (`/marketplace/kategori/{categorySlug}`) is BLOCKED until "Product owner
 * adds category codes and public slugs to `marketplace-catalog.md`" and
 * "This spec then references them; it must not define them."
 *
 * This batch's brief is narrower than that routing question: `products`
 * needs SOME grouping column so a product row can answer "which of the
 * catalogue's three category headings is this," because `product_variants`'
 * scope decision (`ProductCode::GRAVESTONE_CODES`) and any future browse
 * grouping both need it. The decision taken here:
 *
 *   - NO separate `marketplace_categories` table with invented category
 *     codes/slugs/IDs. That would be exactly the second-source-of-truth
 *     `AGENTS.md` §Documentation forbids, compounding a question the spec
 *     itself flags as open rather than resolving it.
 *   - INSTEAD: `products.category` is a plain string column holding one of
 *     THREE short, code-safe keys defined ONCE in this class
 *     (`FLOWERS` / `GRAVESTONES` / `GRAVE_CARE`), each directly and
 *     losslessly derived from the catalogue's own three `###` heading texts
 *     (Karangan Bunga / Batu Nisan / Perawatan Makam — see `label()` below,
 *     which returns those exact heading strings). No new fact is asserted;
 *     this is a code-safe alias for text the catalogue already contains, not
 *     a new taxonomy. `Product::category` is validated against
 *     `KNOWN_KEYS` the same way every other closed-list column in this
 *     codebase is validated, and `ProductCode::categoryFor()` is the single
 *     place that maps a product code to one of these three keys, so no
 *     other file re-derives that mapping.
 *
 * This is NOT a resolution of the public routing question — `label()`'s
 * return values are Indonesian display text, not a URL slug, and this class
 * makes no claim about what `{categorySlug}` should be. That question stays
 * BLOCKED exactly as the spec says, pending the product owner's decision;
 * this class only unblocks the narrower "master-data grouping column" need
 * this batch actually has.
 */
final class MarketplaceProductCategory
{
    /** Catalogue heading: "Karangan Bunga" (L5). */
    public const string FLOWERS = 'FLOWERS';

    /** Catalogue heading: "Batu Nisan" (L10). */
    public const string GRAVESTONES = 'GRAVESTONES';

    /** Catalogue heading: "Perawatan Makam" (L25). */
    public const string GRAVE_CARE = 'GRAVE_CARE';

    /**
     * In the catalogue's own heading order.
     *
     * @var list<string>
     */
    public const array KNOWN_KEYS = [
        self::FLOWERS,
        self::GRAVESTONES,
        self::GRAVE_CARE,
    ];

    public static function isKnown(string $key): bool
    {
        return in_array($key, self::KNOWN_KEYS, true);
    }

    /**
     * @throws InvalidArgumentException when `$key` is not one of
     *                                  `self::KNOWN_KEYS`.
     */
    public static function assertKnown(string $key): void
    {
        if (! self::isKnown($key)) {
            throw new InvalidArgumentException(
                "Unknown marketplace product category [{$key}]. Known keys: ".implode(', ', self::KNOWN_KEYS).'.'
            );
        }
    }

    /**
     * The exact Indonesian heading text `marketplace-catalog.md` uses for
     * `$key` — the single place this mapping is written down, so a seed
     * migration or a future display surface never retypes it independently.
     *
     * @throws InvalidArgumentException when `$key` is not one of
     *                                  `self::KNOWN_KEYS`.
     */
    public static function label(string $key): string
    {
        self::assertKnown($key);

        return match ($key) {
            self::FLOWERS => 'Karangan Bunga',
            self::GRAVESTONES => 'Batu Nisan',
            self::GRAVE_CARE => 'Perawatan Makam',
        };
    }
}
