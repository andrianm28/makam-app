<?php

declare(strict_types=1);

use App\Domain\Marketplace\ProductCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ---------------------------------------------------------------------------
 * THIS IS DUMMY / PLACEHOLDER DATA — explicitly authorized by the user for
 * PUBLIC DEV DISPLAY, NOT real vendor or pricing data
 * ---------------------------------------------------------------------------
 * `2026_07_26_180000_create_products_table.php` and `2026_07_26_180200_seed_
 * marketplace_products_and_variants.php` deliberately left `base_price_idr`
 * `NULL` for all nine seeded products: no real vendor existed to set a real
 * commercial price, and seeding a plausible-looking but unverified figure
 * would have misrepresented an invented number as an approved fact.
 *
 * That premise has since changed for THIS environment only. The user has
 * explicitly authorized filling `base_price_idr` (and adding a vendor name
 * and a photo) with CLEARLY-FICTIONAL placeholder data so that
 * `dev.makam.co.id` — a real, intentionally public, non-production host by
 * design decision (`docs/operations/dev-staging-environment.md` §"Network
 * exposure", ADR-0031: *"the user explicitly requested making dev public...
 * and reaffirmed the request"*) — has something realistic-looking to render
 * instead of blank/NULL prices and vendor fields on every product card. Per
 * that same document's §4, "Staging uses synthetic data or a formally
 * approved, irreversibly sanitized dataset" — synthetic dummy data is the
 * CORRECT content type for this host, not a shortcut around the honesty
 * principle the original NULL seed enforced; this migration does not touch
 * any production database, and production has no marketplace vendors seeded
 * by this or any other migration.
 *
 * None of the following is real:
 *   - `vendor_name` values are invented, generic-sounding CV/UD-style
 *     business names chosen specifically to NOT resemble any real, findable
 *     Indonesian funeral-services vendor. No `vendors` table exists yet
 *     (see the create-table migration's own doc block on why) — this is
 *     intentionally a single denormalized string per product, not a
 *     multi-vendor model, and is expected to be replaced wholesale once a
 *     real `vendors`/vendor-listing table and a real vendor onboarding batch
 *     exist.
 *   - `base_price_idr` values are plausible-for-the-product-type but
 *     entirely invented IDR figures, not sourced from any vendor quote,
 *     approved price list, or other document in this repository.
 *   - `photo_path` values point at hand-authored abstract/line-art SVG
 *     illustrations (`public/images/marketplace/*.svg`) chosen deliberately
 *     to avoid the far more misleading alternative of a fabricated
 *     "photograph" of a product that does not exist. They are illustrative
 *     placeholders, not real product photography.
 *
 * A future real-vendor batch is expected to REPLACE every value this
 * migration writes (vendor name, price, photo) once real vendors onboard —
 * this migration's `up()` is not meant to be the last word on any of the
 * three new columns, only a bridge so the dev environment is not showing
 * empty/NULL commercial fields to the public in the meantime.
 *
 * ---------------------------------------------------------------------------
 * Why a new migration, not an edit to the already-run seed migration
 * ---------------------------------------------------------------------------
 * `2026_07_26_180200_seed_marketplace_products_and_variants.php` has already
 * migrated on this host. Editing an already-run migration is exactly the
 * migration-hygiene mistake `AGENTS.md` §"Database, migrations, and
 * delivery" (expand/contract) warns against — a host that already ran it
 * would never re-run the edited version, silently drifting from a host that
 * migrates fresh. This migration expands the schema (two new nullable
 * columns) and then backfills the nine existing rows in the same `up()`,
 * which is the standard expand+backfill shape for schema changes ON TOP OF
 * data a prior migration already inserted.
 *
 * ---------------------------------------------------------------------------
 * `price_version` — incremented to `2`, not left at `1`
 * ---------------------------------------------------------------------------
 * Per the create-table migration's own doc block, `price_version` "versions
 * this row's own name/description/price DEFINITION... the first published
 * cut of this catalogue entry" — every row was seeded at `1` while
 * `base_price_idr` was `NULL`. Writing a real (even if dummy-for-now)
 * `base_price_idr` for the first time is a new cut of that same definition,
 * so `price_version` becomes `2` here, consistent with the column's own
 * documented intent rather than a `price_versions`-table-style history (that
 * table, `App\Domain\ServiceCatalog\Models\PriceVersion`, is a distinct
 * per-vendor-listing concern this column explicitly is not — see the
 * create-table migration's doc block again).
 *
 * ---------------------------------------------------------------------------
 * Migration timestamp slot
 * ---------------------------------------------------------------------------
 * `2026_07_26_200100` — immediately after `2026_07_26_200000_create_menu_
 * interaction_events_table.php`, the latest existing migration at the time
 * this batch was written; does not reuse or fall inside any other batch's
 * reserved range (`2026_07_26_180000`-`182999` marketplace-catalogue,
 * `190000`-`190399` cemetery-directory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('vendor_name')->nullable()->after('category');
            $table->string('photo_path')->nullable()->after('description');
        });

        $now = now();

        // Shape: [code, vendor_name, base_price_idr, photo_path]
        //
        // Prices are deliberately distinct per product within each family's
        // plausible IDR band (this batch's brief: flowers ~150rb-400rb,
        // gravestones ~2-4 juta, grave-care ~100rb-1jt depending on
        // frequency) so no two products accidentally share a figure that
        // would read as a copy-paste placeholder rather than per-product
        // pricing. See this migration's own class-level doc block: every
        // value below is clearly-fictional dummy data, not a real quote.
        $dummyData = [
            [ProductCode::FLOWER_BOARD, 'CV Berkah Karangan Bunga', 350_000, 'images/marketplace/flower-board.svg'],
            [ProductCode::FLOWER_PETAL_PACKAGE, 'UD Sekar Wangi Abadi', 175_000, 'images/marketplace/flower-petal-package.svg'],
            [ProductCode::GRAVESTONE_GRANITE, 'CV Nisan Granit Sentosa', 3_200_000, 'images/marketplace/gravestone-granite.svg'],
            [ProductCode::GRAVESTONE_MARBLE, 'UD Marmer Damai Abadi', 3_800_000, 'images/marketplace/gravestone-marble.svg'],
            [ProductCode::GRAVESTONE_CALLIGRAPHY, 'CV Kaligrafi Nisan Barokah', 3_950_000, 'images/marketplace/gravestone-calligraphy.svg'],
            [ProductCode::GRAVE_CARE_MONTHLY, 'UD Taman Damai Perawatan', 150_000, 'images/marketplace/grave-care-monthly.svg'],
            [ProductCode::GRAVE_CARE_QUARTERLY, 'CV Perawatan Makam Sentosa', 350_000, 'images/marketplace/grave-care-quarterly.svg'],
            [ProductCode::GRAVE_CARE_SEMIANNUAL, 'UD Amanah Perawatan Makam', 600_000, 'images/marketplace/grave-care-semiannual.svg'],
            [ProductCode::GRAVE_CARE_ANNUAL, 'CV Damai Abadi Perawatan', 950_000, 'images/marketplace/grave-care-annual.svg'],
        ];

        foreach ($dummyData as [$code, $vendorName, $basePriceIdr, $photoPath]) {
            DB::table('products')
                ->where('code', $code)
                ->update([
                    'vendor_name' => $vendorName,
                    'base_price_idr' => $basePriceIdr,
                    'photo_path' => $photoPath,
                    'price_version' => 2,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Contract side of expand/contract: restore the three touched
        // columns on the nine known rows to this batch's pre-existing state
        // (NULL price/vendor/photo, price_version back to 1) before dropping
        // the columns — mirrors the honesty-driven NULL state
        // `2026_07_26_180200_seed_marketplace_products_and_variants.php`
        // originally seeded, in case this migration is ever rolled back
        // without also rolling back that one.
        DB::table('products')
            ->whereIn('code', ProductCode::KNOWN_CODES)
            ->update([
                'vendor_name' => null,
                'base_price_idr' => null,
                'photo_path' => null,
                'price_version' => 1,
                'updated_at' => now(),
            ]);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['vendor_name', 'photo_path']);
        });
    }
};
