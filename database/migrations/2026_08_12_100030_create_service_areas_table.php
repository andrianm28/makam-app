<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `service_areas` — the geographic areas a vendor delivers to, and what each
 * one costs to deliver into.
 *
 * `area_code` is a vendor-supplied free string, NOT a platform region
 * taxonomy. No closed list of Indonesian regions is invented here — that
 * would be a rival source of truth, the same defect the category-code
 * question (`App\Domain\Marketplace\MarketplaceProductCategory`'s doc block)
 * is blocked on. Vendors type their own area codes/labels; this table does
 * not validate them against any administrative-boundary list.
 *
 * Money is integer minor units (`delivery_fee_minor`), never a float or a
 * decimal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_areas', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('vendor_id');
            $table->string('area_code', 64);
            $table->string('area_label');
            $table->unsignedBigInteger('delivery_fee_minor')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete();
            $table->unique(['vendor_id', 'area_code'], 'service_areas_vendor_area_unique');
            $table->index('area_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_areas');
    }
};
