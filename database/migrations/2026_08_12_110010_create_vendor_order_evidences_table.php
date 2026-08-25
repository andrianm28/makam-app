<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_order_evidences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vendor_order_id');
            $table->string('file_path', 500);
            $table->string('evidence_type', 32);
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->foreign('vendor_order_id')->references('id')->on('vendor_orders')->restrictOnDelete();
            $table->index('vendor_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_order_evidences');
    }
};
