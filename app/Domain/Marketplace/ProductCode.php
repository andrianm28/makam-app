<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use InvalidArgumentException;

/**
 * The closed list of `products.code` values — the nine marketplace product
 * codes `docs/product/marketplace-catalog.md` "Categories and minimum
 * products" section names (L7-L30), in that document's own listed order.
 * Plain string column with application-layer validation, not a Postgres enum
 * type — matching this codebase's established convention for closed-list
 * string columns (`App\Domain\Faq\FaqArticlePublishState`,
 * `App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome`,
 * `App\Platform\IdentityAccess\Scopes\ScopeEntityType`). Native PHP backed
 * enums exist elsewhere in this codebase (`App\Platform\FeatureGate\Modes\*`,
 * `App\Platform\Audit\AuditOutcome`) but every OTHER closed list that backs a
 * DB column — which is what this one does — uses this plain-class shape, so
 * this class follows that convention rather than the enum one.
 *
 * `.kiro/specs/funeral-marketplace-and-vendor-portal/tasks.md` "Canonical
 * product codes": *"The nine identifiers below are quoted from the
 * catalogue, not defined here... If this table and the catalogue ever
 * disagree, the catalogue wins and this table is the defect."* This class
 * plays the same role in code that table plays in the spec: a single
 * definition, traceable to the catalogue, that seeders/models/tests consume
 * instead of restating the list. `tests/Feature/Domain/Marketplace/
 * ProductCatalogueSeedTest.php` asserts this list and the live catalogue
 * document agree, so drift between the two fails CI.
 *
 * Sprint plan `S4-T1`: *"enums derive from the catalogue, incl. the 9
 * marketplace product codes."*
 *
 * ---------------------------------------------------------------------------
 * `categoryFor()` and the category-code open question
 * ---------------------------------------------------------------------------
 * The catalogue defines nine product codes but zero category codes — see
 * `App\Domain\Marketplace\MarketplaceProductCategory`'s own doc block for the
 * full reasoning. `categoryFor()` below maps each product code to one of
 * that class's three code-safe category keys, purely by which catalogue
 * heading the product is listed under — it does not invent a category code,
 * it groups by the one closed list this batch DOES define.
 */
final class ProductCode
{
    // --- Karangan Bunga (docs/product/marketplace-catalog.md L5-L8) ---
    public const string FLOWER_BOARD = 'FLOWER_BOARD';

    public const string FLOWER_PETAL_PACKAGE = 'FLOWER_PETAL_PACKAGE';

    // --- Batu Nisan (L10-L23) ---
    public const string GRAVESTONE_GRANITE = 'GRAVESTONE_GRANITE';

    public const string GRAVESTONE_MARBLE = 'GRAVESTONE_MARBLE';

    public const string GRAVESTONE_CALLIGRAPHY = 'GRAVESTONE_CALLIGRAPHY';

    // --- Perawatan Makam (L25-L30) ---
    public const string GRAVE_CARE_MONTHLY = 'GRAVE_CARE_MONTHLY';

    public const string GRAVE_CARE_QUARTERLY = 'GRAVE_CARE_QUARTERLY';

    public const string GRAVE_CARE_SEMIANNUAL = 'GRAVE_CARE_SEMIANNUAL';

    public const string GRAVE_CARE_ANNUAL = 'GRAVE_CARE_ANNUAL';

    /**
     * In the catalogue's own listed order (Karangan Bunga, then Batu Nisan,
     * then Perawatan Makam) — the order the seed migration inserts in and
     * `products.sort_order` mirrors, 1-indexed.
     *
     * @var list<string>
     */
    public const array KNOWN_CODES = [
        self::FLOWER_BOARD,
        self::FLOWER_PETAL_PACKAGE,
        self::GRAVESTONE_GRANITE,
        self::GRAVESTONE_MARBLE,
        self::GRAVESTONE_CALLIGRAPHY,
        self::GRAVE_CARE_MONTHLY,
        self::GRAVE_CARE_QUARTERLY,
        self::GRAVE_CARE_SEMIANNUAL,
        self::GRAVE_CARE_ANNUAL,
    ];

    /**
     * The three Batu Nisan codes — the only product family the catalogue's
     * "Required variant attributes where applicable" block (L16-L23) is
     * positioned under, directly between the Batu Nisan product list and the
     * Perawatan Makam heading. `FLOWER_*` and `GRAVE_CARE_*` are
     * deliberately NOT included: the catalogue names no variant attributes
     * for either family, and `product_variants` rows only ever exist for a
     * product whose code is in this list — see
     * `App\Domain\Marketplace\Models\ProductVariant::booted()`.
     *
     * @var list<string>
     */
    public const array GRAVESTONE_CODES = [
        self::GRAVESTONE_GRANITE,
        self::GRAVESTONE_MARBLE,
        self::GRAVESTONE_CALLIGRAPHY,
    ];

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::KNOWN_CODES, true);
    }

    /**
     * @throws InvalidArgumentException when `$code` is not one of
     *                                  `self::KNOWN_CODES`.
     */
    public static function assertKnown(string $code): void
    {
        if (! self::isKnown($code)) {
            throw new InvalidArgumentException(
                "Unknown marketplace product code [{$code}]. Known codes: ".implode(', ', self::KNOWN_CODES).'.'
            );
        }
    }

    /**
     * Whether `$code`'s product carries `product_variants` rows in this
     * batch — true only for the three Batu Nisan codes. See
     * `GRAVESTONE_CODES`'s own doc block for why the other two families are
     * excluded.
     */
    public static function requiresVariants(string $code): bool
    {
        return in_array($code, self::GRAVESTONE_CODES, true);
    }

    /**
     * Maps a known product code to its `MarketplaceProductCategory` key,
     * purely by which catalogue heading it is listed under. Throws for an
     * unknown code the same way `assertKnown()` does — this is not a
     * fallback/default lookup.
     */
    public static function categoryFor(string $code): string
    {
        self::assertKnown($code);

        return match (true) {
            in_array($code, [self::FLOWER_BOARD, self::FLOWER_PETAL_PACKAGE], true) => MarketplaceProductCategory::FLOWERS,
            in_array($code, self::GRAVESTONE_CODES, true) => MarketplaceProductCategory::GRAVESTONES,
            default => MarketplaceProductCategory::GRAVE_CARE,
        };
    }
}
