<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('vendor_id');
            $table->unsignedBigInteger('listing_id');
            $table->string('customer_name', 255);
            $table->string('customer_phone', 32);
            $table->string('customer_email', 255);
            $table->string('status', 32)->default('MENUNGGU_VENDOR');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete();
            $table->foreign('listing_id')->references('id')->on('vendor_listings')->restrictOnDelete();
            $table->index('vendor_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_orders');
    }
};
