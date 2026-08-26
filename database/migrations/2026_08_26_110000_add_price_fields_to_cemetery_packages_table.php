<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds package/class-level pricing to `cemetery_packages` — the real gap a
 * scoping investigation (25 Aug 2026) found: `Cemetery` already carries an
 * attributed `price_min`/`price_max` RANGE (`2026_07_26_190000_create_
 * cemeteries_table.php`), but no column anywhere records a price for one
 * specific package/class row (e.g. "Makam Tumpang Kelas A" vs "Kelas B"),
 * so the public site could only ever show one aggregate range per cemetery.
 *
 * ---------------------------------------------------------------------------
 * Column shape — deliberately copied from `cemeteries`' own price columns,
 * not the `Money`/minor-unit + append-only `price_versions` convention
 * ---------------------------------------------------------------------------
 * Checked directly before choosing, not assumed: this codebase has TWO
 * established price conventions.
 *
 * 1. `App\Platform\FinancialLedger\Money` + a `*_minor` bigint column
 *    (`vendor_listings.price_minor`, `marketplace_order_items.line_total_
 *    minor`, `renewal_quotes.amount_minor`) — used for a TRANSACTABLE line
 *    amount that flows into a cart/quote/order total.
 * 2. `cemeteries.price_min`/`price_max` — plain nullable `decimal(14,2)`,
 *    paired with `price_currency`/`price_source`/`price_effective_at` — used
 *    for an INDICATIVE, attributed figure that is never charged, never
 *    totalled, and is explicitly framed "Perlu konfirmasi" (see
 *    `CemeteryPresenter`/`CemeteryAvailabilityIntent`'s own doc blocks).
 *
 * A package/class price is the second kind: it is not added to a cart, not
 * quoted, not paid — it is the SAME kind of indicative, attributed figure
 * `cemeteries.price_min`/`price_max` already is, just resolved at a finer
 * (package/class, not whole-cemetery) granularity. Reusing the `Money`/
 * `price_versions` machinery here would overstate this figure as a firm,
 * versioned, transactable price it structurally is not — `ServiceDefinition`
 * prices feed real `Quote`/`Order` totals; this one never does. This
 * migration therefore mirrors `cemeteries`' own five columns exactly rather
 * than inventing a third shape:
 *
 * - `price_min`/`price_max` — `decimal(14,2)`, nullable. Both null (the
 *   default) is a real, honest state — "showing nothing is honest; showing
 *   a number with an invented `price_source` would not be" (`cemeteries`
 *   migration's own words, equally true here).
 * - `price_currency` — `string(3)`, defaults `IDR` — this MVP's launch
 *   cities transact in nothing else, matching `cemeteries.price_currency`.
 * - `price_source` — nullable `string`. `CemeteryPresenter::
 *   packagePriceAttribution()` already renders an honest "Sumber tidak
 *   tercatat" fallback when this is blank (mirroring `priceAttribution()`
 *   for the cemetery-level figure), so leaving it optional here does not
 *   create a silently-unattributed price on the public page.
 * - `price_effective_at` — nullable `timestamp`. NOT admin-editable directly
 *   (see `CemeteryPackage::booted()`) — it is stamped to "now" automatically
 *   whenever an admin changes the priced fields, the same "recorded at, not
 *   hand-entered" discipline `PriceVersion.effective_from` uses for its own
 *   append-only rows, just without the append-only/versioning machinery
 *   this indicative figure does not need.
 *
 * No index is added: `cemetery_packages_cemetery_active_idx` already covers
 * this table's one real access path (a cemetery's active packages, ordered
 * by `sort_order`/`name` — see `CemeteryPublicQuery::activePackages()`), and
 * these new columns are read-not-filtered on that path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cemetery_packages', function (Blueprint $table) {
            $table->decimal('price_min', 14, 2)->nullable()->after('description');
            $table->decimal('price_max', 14, 2)->nullable()->after('price_min');
            $table->string('price_currency', 3)->default('IDR')->after('price_max');
            $table->string('price_source')->nullable()->after('price_currency');
            $table->timestamp('price_effective_at')->nullable()->after('price_source');
        });
    }

    public function down(): void
    {
        Schema::table('cemetery_packages', function (Blueprint $table) {
            $table->dropColumn([
                'price_min',
                'price_max',
                'price_currency',
                'price_source',
                'price_effective_at',
            ]);
        });
    }
};
