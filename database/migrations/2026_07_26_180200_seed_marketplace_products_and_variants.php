<?php

declare(strict_types=1);

use App\Domain\Marketplace\ProductCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds `products` and `product_variants` from `docs/product/
 * marketplace-catalog.md` — the single source of truth this batch's brief
 * names, and the document `App\Domain\Marketplace\ProductCode` is itself
 * traceable to.
 *
 * ---------------------------------------------------------------------------
 * Why a DATA MIGRATION, not a `database/seeders/*` class
 * ---------------------------------------------------------------------------
 * Same reasoning as `2026_07_26_170400_seed_faq_categories_and_articles.php`
 * (see that migration's own doc block): nothing in this repository's CI or
 * deployment process runs `php artisan db:seed`, so real content that must
 * exist in every environment goes in a migration, which `php artisan
 * migrate` always runs.
 *
 * ---------------------------------------------------------------------------
 * Product count and codes — exactly the catalogue's nine, nothing invented
 * ---------------------------------------------------------------------------
 * `marketplace-catalog.md` "Categories and minimum products" lists exactly
 * nine codes across three categories (2 + 3 + 4). This migration seeds
 * exactly those nine, via `ProductCode::KNOWN_CODES`, in that class's (and
 * the catalogue's) own order.
 * `tests/Feature/Domain/Marketplace/ProductCatalogueSeedTest.php` re-parses
 * the live catalogue document at test time and asserts the seeded codes
 * match it exactly — a genuine drift detector in both directions, not just
 * a restatement of this migration's own array.
 *
 * `name` values are the catalogue's own labels, verbatim (its table column
 * next to each code — e.g. `GRAVESTONE_GRANITE` -> "Granit").
 * `description` values are authored by this migration (the catalogue names
 * no per-product description text) and are deliberately generic/structural
 * — they do NOT assert a specific price, turnaround time, SLA, or
 * commercial promise that no document in this repository actually commits
 * to, the same restraint `2026_07_26_170400_seed_faq_categories_and_
 * articles.php`'s own doc block applied to its payment/Urgent-related
 * answers.
 *
 * `base_price_idr` is seeded `NULL` for all nine rows — see
 * `2026_07_26_180000_create_products_table.php`'s own doc block for why
 * (no vendor exists yet to set a real price; a fabricated figure would be
 * an unverified fact, not master data).
 *
 * ---------------------------------------------------------------------------
 * Variants — six rows, Batu Nisan only
 * ---------------------------------------------------------------------------
 * Two example `product_variants` rows per Batu Nisan product (six total) —
 * "a small number of realistic example variants," per this batch's brief.
 * `FLOWER_*` and `GRAVE_CARE_*` products seed with zero variant rows — see
 * `2026_07_26_180100_create_product_variants_table.php`'s own doc block for
 * why that is a deliberate reading of the catalogue text's structure, not an
 * omission.
 *
 * `preview_image_path` values below are PLACEHOLDER STRINGS ONLY — no file
 * exists at that path in any storage disk in this batch. A real upload/asset
 * pipeline for gravestone preview images is
 * `.kiro/specs/funeral-marketplace-and-vendor-portal/tasks.md`'s separate
 * "Implement gravestone configurator schema and preview" task, not yet
 * built. `inscription_text_example` is similarly an illustrative sample for
 * that future preview, not real customer data.
 *
 * Migration timestamp slot: see
 * `2026_07_26_180000_create_products_table.php`'s own doc block
 * (`2026_07_26_180000`-`2026_07_26_182999`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // --- products — marketplace-catalog.md's own listed codes/labels/
        // order. Shape: [code, name, description]
        $products = [
            [
                ProductCode::FLOWER_BOARD,
                'Karangan Bunga Papan',
                'Karangan bunga papan ucapan duka cita, dipasang di lokasi pemakaman atau rumah duka sesuai jadwal yang dikonfirmasi bersama vendor.',
            ],
            [
                ProductCode::FLOWER_PETAL_PACKAGE,
                'Paket Bunga Tabur',
                'Paket bunga tabur untuk prosesi pemakaman, berisi bunga segar yang ditaburkan pada saat pemakaman berlangsung.',
            ],
            [
                ProductCode::GRAVESTONE_GRANITE,
                'Granit',
                'Batu nisan berbahan granit, tersedia dalam beberapa pilihan ukuran dan warna sesuai variannya.',
            ],
            [
                ProductCode::GRAVESTONE_MARBLE,
                'Marmer',
                'Batu nisan berbahan marmer, tersedia dalam beberapa pilihan ukuran dan warna sesuai variannya.',
            ],
            [
                ProductCode::GRAVESTONE_CALLIGRAPHY,
                'Kaligrafi',
                'Batu nisan dengan tambahan ukiran kaligrafi sesuai teks inskripsi dan gaya kaligrafi yang dipilih.',
            ],
            [
                ProductCode::GRAVE_CARE_MONTHLY,
                'Bulanan',
                'Layanan perawatan makam berkala dengan siklus bulanan.',
            ],
            [
                ProductCode::GRAVE_CARE_QUARTERLY,
                '3 Bulan',
                'Layanan perawatan makam berkala dengan siklus tiga bulan.',
            ],
            [
                ProductCode::GRAVE_CARE_SEMIANNUAL,
                '6 Bulan',
                'Layanan perawatan makam berkala dengan siklus enam bulan.',
            ],
            [
                ProductCode::GRAVE_CARE_ANNUAL,
                'Tahunan',
                'Layanan perawatan makam berkala dengan siklus tahunan.',
            ],
        ];

        $productIds = [];

        foreach ($products as $index => [$code, $name, $description]) {
            $productIds[$code] = DB::table('products')->insertGetId([
                'code' => $code,
                'category' => ProductCode::categoryFor($code),
                'name' => $name,
                'description' => $description,
                'base_price_idr' => null,
                'price_version' => 1,
                'is_active' => true,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // --- product_variants — two illustrative rows per Batu Nisan
        // product. Shape: [product code, size, material, color,
        // calligraphy style, inscription text example, preview image path]
        $variants = [
            [ProductCode::GRAVESTONE_GRANITE, '60 x 80 cm', 'Granit Hitam', 'Hitam', null, null, 'marketplace/gravestone-variants/placeholder-gravestone-granite-hitam.jpg'],
            [ProductCode::GRAVESTONE_GRANITE, '80 x 100 cm', 'Granit Abu-abu', 'Abu-abu', null, null, 'marketplace/gravestone-variants/placeholder-gravestone-granite-abu-abu.jpg'],

            [ProductCode::GRAVESTONE_MARBLE, '60 x 80 cm', 'Marmer Putih', 'Putih', null, null, 'marketplace/gravestone-variants/placeholder-gravestone-marmer-putih.jpg'],
            [ProductCode::GRAVESTONE_MARBLE, '80 x 100 cm', 'Marmer Krem', 'Krem', null, null, 'marketplace/gravestone-variants/placeholder-gravestone-marmer-krem.jpg'],

            [ProductCode::GRAVESTONE_CALLIGRAPHY, '60 x 80 cm', 'Granit Hitam', 'Hitam', 'Naskhi', 'Contoh teks: nama almarhum/almarhumah, tanggal lahir, dan tanggal wafat.', 'marketplace/gravestone-variants/placeholder-gravestone-kaligrafi-naskhi.jpg'],
            [ProductCode::GRAVESTONE_CALLIGRAPHY, '80 x 100 cm', 'Marmer Putih', 'Putih', 'Diwani', 'Contoh teks: nama almarhum/almarhumah, tanggal lahir, dan tanggal wafat.', 'marketplace/gravestone-variants/placeholder-gravestone-kaligrafi-diwani.jpg'],
        ];

        $sortOrderByProduct = [];

        foreach ($variants as [$code, $size, $material, $color, $calligraphyStyle, $inscriptionExample, $previewImagePath]) {
            $sortOrderByProduct[$code] = ($sortOrderByProduct[$code] ?? 0) + 1;

            DB::table('product_variants')->insert([
                'product_id' => $productIds[$code],
                'size' => $size,
                'material' => $material,
                'color' => $color,
                'calligraphy_style' => $calligraphyStyle,
                'inscription_text_example' => $inscriptionExample,
                'preview_image_path' => $previewImagePath,
                'sort_order' => $sortOrderByProduct[$code],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $productIds = DB::table('products')
            ->whereIn('code', ProductCode::KNOWN_CODES)
            ->pluck('id');

        DB::table('product_variants')->whereIn('product_id', $productIds)->delete();
        DB::table('products')->whereIn('code', ProductCode::KNOWN_CODES)->delete();
    }
};
