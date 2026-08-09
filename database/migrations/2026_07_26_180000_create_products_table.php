<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `products` — sprint S4-T1's marketplace-product-catalogue slice.
 * `.kiro/specs/funeral-marketplace-and-vendor-portal/design.md`'s Data
 * section names this table; `docs/product/marketplace-catalog.md` is the
 * single source of truth this table's seed migration
 * (`2026_07_26_180200_seed_marketplace_products_and_variants.php`) reads
 * from.
 *
 * ---------------------------------------------------------------------------
 * Scope of THIS batch — catalogue master data only, not the marketplace
 * ---------------------------------------------------------------------------
 * This migration builds the "underlying `products`/`product_variants`
 * master data" S4-T8 (marketplace browse skeleton, `funeral-marketplace-
 * and-vendor-portal` AC1-AC3) will read from — nothing more. It deliberately
 * does NOT create `vendors`, `carts`, `marketplace_orders`, or any other
 * table `design.md`'s Data section lists — those need vendors and a payment
 * decision that does not exist yet (S4-T8 and later, per
 * `docs/planning/sprint-plan.md` §7's task table). Concretely:
 *
 *   - NO `vendor_id` column. No `vendors` table exists in this repository
 *     yet, so there is nothing to reference; a nullable FK to a
 *     not-yet-existing table would be pure scope invention. When vendors
 *     land, per-vendor LISTINGS (price, stock/availability, service area,
 *     schedule, delivery fee rule, lead time, cancellation policy, evidence
 *     requirement — `marketplace-catalog.md` §"Required product data") are
 *     expected to belong to a future vendor-listing table that references
 *     BOTH this table and `vendors`, not to columns bolted onto this one —
 *     those attributes are inherently per-vendor-offer, not per catalogue
 *     product, and modelling them here now would misrepresent a single
 *     platform-wide value as if every vendor offered the same terms.
 *   - NO `stock`/`service_area`/`schedule`/`delivery_fee_rule`/
 *     `production_lead_time`/`cancellation_policy`/`evidence_requirement`
 *     columns, for the same reason — all seven are vendor-offer attributes
 *     per the catalogue's own "Required product data" list, not static
 *     product-definition attributes. Modelling them here would need to be
 *     redone (or dual-write) once a real vendor listing table exists.
 *   - `photos`/`variants` from that same required-data list: `variants` is
 *     `product_variants` (this batch, see that migration). `photos` is a
 *     future upload/media-library concern (private-quarantine pipeline,
 *     `AGENTS.md` §Authentication and uploads) out of scope for static seed
 *     master data — no product photo exists to seed today.
 *
 * ---------------------------------------------------------------------------
 * `code` — the nine catalogue product codes, closed list
 * ---------------------------------------------------------------------------
 * Plain string column, application-layer-validated against
 * `App\Domain\Marketplace\ProductCode::KNOWN_CODES` — not a Postgres enum
 * type, matching this codebase's established convention for closed-list
 * string columns (see that class's own doc block for the full precedent
 * list and the sprint task's "enums derive from the catalogue" framing).
 *
 * ---------------------------------------------------------------------------
 * `category` — the OPEN category-code question, and the narrow resolution
 * taken here (full reasoning lives in the class this defers to)
 * ---------------------------------------------------------------------------
 * `.kiro/specs/funeral-marketplace-and-vendor-portal/tasks.md` flags,
 * explicitly and un-resolved, that the catalogue defines nine product codes
 * but ZERO category codes — Karangan Bunga / Batu Nisan / Perawatan Makam
 * exist only as Markdown `###` headings. That section is equally explicit
 * that inventing a category code/slug in this spec would be "a second,
 * competing source of truth" and that category ROUTING
 * (`/marketplace/kategori/{categorySlug}`) stays BLOCKED pending a product
 * owner decision.
 *
 * Decision taken here (see `App\Domain\Marketplace\
 * MarketplaceProductCategory`'s own doc block for the full writeup this
 * summarises): NO new `marketplace_categories` lookup table. `category` is a
 * plain string column holding one of three code-safe keys
 * (`FLOWERS`/`GRAVESTONES`/`GRAVE_CARE`) defined ONCE in that class, each a
 * lossless alias for one of the catalogue's own three heading texts — not an
 * invented taxonomy, and not an attempt to answer the routing-slug question,
 * which stays open. This column exists only because `product_variants`'
 * product-family scoping and any future browse grouping both need SOME way
 * to answer "which of the three catalogue families is this row," and a
 * plain validated string on the one table that needs it is strictly less
 * invention than a second table nobody asked for.
 *
 * ---------------------------------------------------------------------------
 * `base_price_idr` — nullable, seeded NULL, not a fabricated figure
 * ---------------------------------------------------------------------------
 * The catalogue's "Required product data" list names "price/version" as a
 * required field, so the column exists. No vendor exists yet to set a real
 * commercial price (see the vendor-scope note above), and no other document
 * in this repository commits to a specific IDR figure for any of these nine
 * products. Rather than seed a plausible-looking but unverified number that
 * a later reader could mistake for a real, approved price, this column is
 * nullable and every seed row leaves it `NULL` — "not yet priced," an
 * honest state, not an invented fact. `price_version` starts at `1` for
 * every row regardless (it versions this row's own name/description/price
 * DEFINITION, i.e. "the first published cut of this catalogue entry" — not
 * a per-vendor-listing price history, which is a distinct future concern).
 *
 * ---------------------------------------------------------------------------
 * Migration timestamp slot
 * ---------------------------------------------------------------------------
 * `2026_07_26_180000` through `2026_07_26_182999` — the next free range
 * after Sprint 4's FAQ batch (`2026_07_26_170000`-`2026_07_26_170400`,
 * itself the range after Sprint 3's re-authentication batch). Reserved for
 * this batch's marketplace-PRODUCT-CATALOGUE-only migrations.
 *
 * CORRECTION, 09 Aug 2026 (marketplace retrofit, finding D-3): the original
 * claim that "neither this table name nor this slot is shared with"
 * `package-and-service-bundles` is FALSE — the ServiceCatalog lane occupies
 * `2026_07_26_180000` through `2026_07_26_180700` (`create_service_*`
 * tables). Ordering is unaffected and deterministic: Laravel orders by full
 * filename, so `create_products_table` sorts before
 * `create_service_definitions_table` despite the shared `180000` prefix, and
 * no Marketplace migration depends on a ServiceCatalog table (nor vice
 * versa). Only the claim was wrong. The mirror-image claim in
 * `2026_07_26_180000_create_service_definitions_table.php:63-70` is owned by
 * the parallel ServiceCatalog lane and was NOT touched here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->string('code', 64)->unique();
            $table->string('category', 32);

            $table->string('name');
            $table->text('description');

            $table->unsignedBigInteger('base_price_idr')->nullable();
            $table->unsignedInteger('price_version')->default(1);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order');

            $table->timestamps();

            // Public/admin browse: active products in catalogue display
            // order, optionally filtered to one category — S4-T8's primary
            // read path.
            $table->index(['category', 'is_active', 'sort_order'], 'products_category_active_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
