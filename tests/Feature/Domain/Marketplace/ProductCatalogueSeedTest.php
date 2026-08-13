<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\MarketplaceProductCategory;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\ProductCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The drift-detection test this batch's brief requires: this does NOT
 * compare the seeded database against `ProductCode::KNOWN_CODES` alone
 * (that would only prove the seed migration agrees with the enum, which is
 * true by construction — see the seed migration's own `up()`). It instead
 * RE-PARSES `docs/product/marketplace-catalog.md` directly, independently of
 * `ProductCode`, and asserts all three — the live catalogue document, the
 * `ProductCode` enum, and the seeded `products` table — agree. Editing the
 * seed data (or the enum) to disagree with the catalogue, OR editing the
 * catalogue without updating the code, fails this test either way.
 */
final class ProductCatalogueSeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Re-parses the "Categories and minimum products" section of
     * `docs/product/marketplace-catalog.md` directly, independently of
     * `ProductCode`, in the catalogue's OWN document order.
     *
     * W-3: the previous parser matched any backtick-quoted ALL_CAPS token
     * anywhere in the file (P-2) and extracted codes only, which made this
     * test blind to a catalogue REORDER (both sides were `sort()`ed) and to
     * a LABEL change (the nine labels were hand-typed literals in a data
     * provider — `tasks.md` §"Seeds and enums must derive from the
     * catalogue" forbids that in "a test fixture"). This parser anchors to
     * the section and extracts the `` `CODE` — Label `` pair from each
     * bullet line, so order and label drift both fail this test.
     *
     * @return list<array{code: string, label: string, category: string}>
     *                                                                    the catalogue's own product rows in document order; `category`
     *                                                                    is the `###` heading text the bullet sits under.
     */
    private function catalogueProductRows(): array
    {
        $path = base_path('docs/product/marketplace-catalog.md');
        $this->assertFileExists($path, 'Canonical catalogue document is missing.');

        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        // Anchor to the section so backtick-quoted ALL_CAPS tokens elsewhere
        // in the document cannot be misattributed to the catalogue (P-2).
        // "\n## " (not "## ") ends the section at the next top-level heading
        // while skipping the "### " category headings inside it.
        $sectionStart = strpos($contents, '## Categories and minimum products');
        $this->assertNotFalse($sectionStart, 'Catalogue section "Categories and minimum products" not found.');
        $sectionEnd = strpos($contents, "\n## ", $sectionStart + 2);
        $this->assertNotFalse($sectionEnd, 'Catalogue section is unterminated.');

        $rows = [];
        $currentCategory = null;
        foreach (preg_split('/\R/', substr($contents, $sectionStart, $sectionEnd - $sectionStart)) ?: [] as $line) {
            if (preg_match('/^### (.+)$/', $line, $heading) === 1) {
                $currentCategory = $heading[1];

                continue;
            }

            if (preg_match('/^- `([A-Z][A-Z0-9_]*)` — (.+)$/u', $line, $bullet) === 1) {
                $this->assertNotNull($currentCategory, "Product bullet [{$line}] appears before any category heading.");
                $rows[] = [
                    'code' => $bullet[1],
                    'label' => trim($bullet[2]),
                    'category' => $currentCategory,
                ];
            }
        }

        return $rows;
    }

    public function test_the_live_catalogue_document_still_names_exactly_nine_product_codes(): void
    {
        $rows = $this->catalogueProductRows();

        $this->assertCount(
            9,
            $rows,
            'docs/product/marketplace-catalog.md no longer lists exactly nine products under "Categories and minimum products" — '.
            'either the catalogue changed (requires a product-approval note per tasks.md) or this test\'s parser broke.'
        );
    }

    public function test_product_code_enum_matches_the_live_catalogue_document_in_document_order(): void
    {
        // W-3: no sort() — the catalogue's own bullet order is asserted, so
        // a REORDER of the lines now fails here instead of being masked.
        $fromDocument = array_column($this->catalogueProductRows(), 'code');

        $this->assertSame(
            $fromDocument,
            ProductCode::KNOWN_CODES,
            'App\Domain\Marketplace\ProductCode::KNOWN_CODES has drifted from docs/product/marketplace-catalog.md.'
        );
    }

    public function test_exactly_nine_products_are_seeded(): void
    {
        $this->assertDatabaseCount('products', 9);
        $this->assertSame(9, count(ProductCode::KNOWN_CODES));
    }

    public function test_seeded_product_codes_match_the_live_catalogue_document_in_document_order(): void
    {
        // W-3: no sort() — document order is asserted against the seed's own
        // sort_order, so a reordered catalogue (or seed) fails here too.
        $fromDocument = array_column($this->catalogueProductRows(), 'code');

        $seeded = Product::query()->orderBy('sort_order')->pluck('code')->all();

        $this->assertSame($fromDocument, $seeded);
    }

    public function test_each_seeded_product_has_the_catalogues_exact_code_category_and_label(): void
    {
        // W-3: labels come from the document, never retyped — the nine
        // hand-typed label literals are gone, so a label change in the
        // catalogue fails this test instead of silently passing. The category
        // key is derived by matching the parsed heading against the domain's
        // OWN label() mapping, so heading ↔ key drift fails in both
        // directions too.
        foreach ($this->catalogueProductRows() as $row) {
            $product = Product::findByCode($row['code']);
            $this->assertNotNull($product, "Expected a seeded product for [{$row['code']}].");

            $categoryKey = null;
            foreach (MarketplaceProductCategory::KNOWN_KEYS as $key) {
                if (MarketplaceProductCategory::label($key) === $row['category']) {
                    $categoryKey = $key;
                    break;
                }
            }
            $this->assertNotNull($categoryKey, "Catalogue heading [{$row['category']}] matches no known category label.");

            $this->assertSame($categoryKey, $product->category, "Product [{$row['code']}] has an unexpected category.");
            $this->assertSame($row['label'], $product->name, "Product [{$row['code']}] has an unexpected name/label.");
            $this->assertNotSame('', trim($product->description), "Product [{$row['code']}] has a blank description.");
            $this->assertTrue($product->is_active);
        }
    }

    public function test_a_product_with_an_unknown_code_cannot_be_saved(): void
    {
        // W-4: the closed-list guard in Product::booted() is the ONLY
        // enforcement of products.code — no DB CHECK exists and the seed
        // migration bypasses it via DB::table()->insert() — so a refactor
        // that dropped the assertKnown() line would otherwise go uncaught.
        // Mirrors the sibling negative test ProductVariantSeedTest
        // (product_id scope), fired through the model's own saving path.
        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        $this->assertNotNull($product);

        $product->code = 'PRODUK_YANG_TIDAK_ADA';

        $this->expectException(InvalidArgumentException::class);

        $product->save();
    }

    public function test_a_product_with_an_unknown_category_cannot_be_saved(): void
    {
        // W-4, category half of the same guard.
        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        $this->assertNotNull($product);

        $product->category = 'KATEGORI_YANG_TIDAK_ADA';

        $this->expectException(InvalidArgumentException::class);

        $product->save();
    }

    /**
     * Originally `test_no_product_has_a_fabricated_base_price`, asserting
     * every `base_price_idr` was `NULL` — see
     * `2026_07_26_180000_create_products_table.php`'s own doc block for why
     * that was the honest state at the time: no vendor existed to set a real
     * commercial price, so seeding a plausible-looking figure would have
     * been an unverified fact masquerading as data.
     *
     * That premise has since changed, for this environment only: the user
     * explicitly authorized clearly-fictional DUMMY vendor/pricing/photo
     * data so `dev.makam.co.id` — a real, intentionally public, non-
     * production host (ADR-0031) — has something realistic-looking to
     * render instead of blank price fields. See
     * `2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products.php`'s
     * own doc block for the full authorization trail and the "future
     * real-vendor batch replaces this" caveat.
     *
     * As of the procedural example-data retrofit the figures themselves are
     * GENERATED by `App\Support\ExampleData\VendorExampleData::basePrice()`
     * (a stable per-code hash folded into 500k increments), so pinning exact
     * amounts here would just restate the generator and rot with it. Per the
     * design's "tests must not hardcode amounts" risk note, the lock is now
     * property-based: every product has `base_price_idr` NOT NULL, positive,
     * a round 500k increment, and >= 500_000 — the honest contract for a
     * dummy price. The `price_version` cut-check is preserved: the dummy-
     * pricing migration writes the first real (dummy-for-now) base price, a
     * new "cut" of each row's price definition per that column's documented
     * intent, so every row must sit on `price_version` 2.
     */
    public function test_every_product_has_a_documented_dummy_base_price(): void
    {
        foreach (ProductCode::KNOWN_CODES as $code) {
            $product = Product::query()->where('code', $code)->first();
            $this->assertNotNull($product, "Expected a seeded product for [{$code}].");
            $this->assertNotNull($product->base_price_idr, "Product [{$code}] should have a seeded dummy base price.");
            $this->assertGreaterThan(0, $product->base_price_idr);
            $this->assertGreaterThanOrEqual(500_000, $product->base_price_idr);
            $this->assertSame(0, $product->base_price_idr % 500_000);
            $this->assertSame(2, $product->price_version, "Product [{$code}] should be on price_version 2 after the dummy-price backfill.");
        }
    }

    /**
     * Renamed from `test_every_product_has_a_clearly_fictional_vendor_name_...`
     * during the 09 Aug 2026 Marketplace retrofit (W-1): the old name claimed
     * the SEEDED vendor names are "clearly fictional", but this body only
     * proves they are non-blank and the photo is a hand-authored SVG. The
     * fictionality marker is applied at the PRESENTATION seam
     * (`App\Livewire\Public\Marketplace\Support\MarketplacePresenter`, asserted
     * by the two route tests); marking the seeded COLUMN values themselves is
     * the ledgered data-layer half (needs a human-gated migration), so a
     * data-layer "clearly fictional" assertion would fail against the current
     * seed and is deliberately not written here.
     */
    public function test_every_product_has_a_non_blank_vendor_name_and_a_placeholder_photo(): void
    {
        // Not a byte-for-byte string match against the migration (that would
        // just restate the migration's own array) — proves the shape/intent
        // instead: every row has a non-blank vendor name, and every photo
        // path points at this batch's hand-authored SVG illustration set
        // rather than a fabricated "photograph" of a nonexistent product.
        foreach (Product::query()->get() as $product) {
            $this->assertNotNull($product->vendor_name, "Product [{$product->code}] should have a placeholder vendor name.");
            $this->assertNotSame('', trim($product->vendor_name), "Product [{$product->code}] has a blank vendor name.");

            $this->assertNotNull($product->photo_path, "Product [{$product->code}] should have a placeholder photo path.");
            $this->assertStringStartsWith('images/marketplace/', $product->photo_path);
            $this->assertStringEndsWith('.svg', $product->photo_path);

            $this->assertFileExists(
                public_path($product->photo_path),
                "Product [{$product->code}]'s photo_path does not resolve to a real file under public/."
            );
        }
    }

    public function test_products_are_ordered_per_the_catalogues_own_listing_order(): void
    {
        $orderedCodes = Product::query()->orderBy('sort_order')->pluck('code')->all();

        $this->assertSame(ProductCode::KNOWN_CODES, $orderedCodes);
    }
}
