<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `product_variants` — `.kiro/specs/funeral-marketplace-and-vendor-portal/
 * design.md`'s Data section names this table alongside `products`.
 *
 * ---------------------------------------------------------------------------
 * Scoped to Batu Nisan (`GRAVESTONE_*`) products ONLY — a deliberate,
 * catalogue-text-driven judgement call, not a uniform shape applied to all
 * nine products
 * ---------------------------------------------------------------------------
 * `docs/product/marketplace-catalog.md`'s "Required variant attributes where
 * applicable" block (L16-L23: size, material, color, inscription text,
 * calligraphy style, preview/reference image) is positioned in the document
 * directly under the Batu Nisan product list (L10-L14) and BEFORE the
 * Perawatan Makam heading (L25) — i.e. textually scoped to Batu Nisan only.
 * Karangan Bunga (L5-L8) and Perawatan Makam (L25-L30) have no variant
 * attributes block of their own anywhere in the catalogue. This migration
 * follows that literal document structure: rows in this table only ever
 * belong to a product whose code is one of `App\Domain\Marketplace\
 * ProductCode::GRAVESTONE_CODES`, enforced at the application layer by
 * `App\Domain\Marketplace\Models\ProductVariant::booted()`.
 *
 * NOTE for a later reader: `.kiro/specs/funeral-marketplace-and-vendor-
 * portal/tasks.md`'s own "Variant attributes" section glosses this
 * differently — it additionally assigns "size" and "preview image" to
 * `FLOWER_*` "as configured" — but that gloss is the SPEC's interpretive
 * addition, not the catalogue itself (the catalogue, which
 * `AGENTS.md` §Marketplace and §Documentation both name as the single source
 * of truth, states no variant attributes for Karangan Bunga at all). This
 * batch's brief was explicit to read the catalogue text's own structure
 * over a uniform-shape assumption, so `FLOWER_*` and `GRAVE_CARE_*` products
 * seed with ZERO `product_variants` rows. If a future product decision
 * confirms flower-board size/preview-image variants are actually wanted,
 * that is a catalogue change (the tasks.md gloss's own stated precondition
 * for adding a variant axis) followed by a new migration — not a retrofit of
 * this one.
 *
 * ---------------------------------------------------------------------------
 * Column shape — exactly the six catalogue-named attributes, no more
 * ---------------------------------------------------------------------------
 * `size`, `material`, `color`, `calligraphy_style`, `inscription_text_example`
 * (catalogue: "inscription text"), `preview_image_path` (catalogue:
 * "preview/reference image") — `tasks.md`: "Do not invent additional
 * variant axes." No price-delta, no SKU, no stock column: those are
 * per-vendor-listing concerns out of this batch's scope, same reasoning as
 * `products`' own migration doc block.
 *
 * `calligraphy_style` and `inscription_text_example` are nullable and, in
 * this batch's seed data, populated only for `GRAVESTONE_CALLIGRAPHY`
 * variants — granite/marble headstones are a materially different product
 * (no calligraphy service) per the catalogue treating `GRAVESTONE_CALLIGRAPHY`
 * as its own distinct product code, not an option on the other two.
 * `inscription_text_example` is explicitly an illustrative SAMPLE string for
 * a future preview, not a customer's actual per-order inscription — real
 * customer-supplied inscription text is an order-time input belonging to
 * `.kiro/specs/funeral-marketplace-and-vendor-portal/tasks.md`'s separate,
 * not-yet-built "Implement gravestone configurator schema and preview" task.
 *
 * `preview_image_path` is seeded with a placeholder string (see the seed
 * migration's own doc block) — no real file exists in storage behind it in
 * this batch; a real upload/asset pipeline is that same future
 * configurator task's concern, not this one's.
 *
 * `product_id` uses `restrictOnDelete()` — same reasoning as
 * `faq_articles.category_id`: this batch's seeded catalogue rows are
 * expected to be stable master data, so an accidental product deletion
 * should fail loudly rather than silently cascade away real variant rows.
 *
 * Migration timestamp slot: see
 * `2026_07_26_180000_create_products_table.php`'s own doc block
 * (`2026_07_26_180000`-`2026_07_26_182999`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->string('size', 32);
            $table->string('material', 64);
            $table->string('color', 32);

            $table->string('calligraphy_style', 64)->nullable();
            $table->text('inscription_text_example')->nullable();
            $table->string('preview_image_path')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['product_id', 'sort_order'], 'product_variants_product_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
