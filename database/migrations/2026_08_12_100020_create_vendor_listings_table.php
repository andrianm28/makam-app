<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `vendor_listings` — one vendor's offer of one catalogue product.
 *
 * `products` stays platform-wide catalogue master data (nine rows, one per
 * `ProductCode`). Everything that varies per vendor lives here, exactly as
 * `2026_07_26_180000_create_products_table.php` anticipated: those attributes
 * "belong to a future vendor-listing table that references BOTH this table and
 * `vendors`". `marketplace-catalog.md` §"Required product data" leads with
 * "vendor", which is why these are per-offer and not per-product.
 *
 * Money is integer minor units (`price_minor`), never a float or a decimal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_listings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('vendor_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('price_minor');
            $table->unsignedInteger('price_version')->default(1);
            $table->string('availability_mode', 32);
            $table->unsignedInteger('stock_quantity')->nullable();
            $table->unsignedSmallInteger('production_lead_time_days')->nullable();
            $table->text('cancellation_policy')->nullable();
            $table->string('evidence_requirement', 32);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->unique(['vendor_id', 'product_id'], 'vendor_listings_offer_unique');
            $table->index(['product_id', 'is_active']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE vendor_listings ADD CONSTRAINT vendor_listings_price_positive CHECK (price_minor > 0)');
        DB::statement("ALTER TABLE vendor_listings ADD CONSTRAINT vendor_listings_availability_mode_known CHECK (availability_mode IN ('STOCKED','MADE_TO_ORDER','SCHEDULED'))");
        DB::statement("ALTER TABLE vendor_listings ADD CONSTRAINT vendor_listings_evidence_known CHECK (evidence_requirement IN ('NONE','PHOTO','DOCUMENT'))");
        // stock_quantity is meaningful only for a STOCKED listing.
        DB::statement("ALTER TABLE vendor_listings ADD CONSTRAINT vendor_listings_stock_only_when_stocked CHECK ((availability_mode = 'STOCKED') OR (stock_quantity IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_listings');
    }
};
