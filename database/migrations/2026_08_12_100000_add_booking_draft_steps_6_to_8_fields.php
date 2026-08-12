<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_drafts', function (Blueprint $table): void {
            $table->string('customer_full_name', 191)->nullable();
            $table->string('customer_mobile', 20)->nullable();
            $table->string('customer_email', 191)->nullable();
            $table->text('customer_address')->nullable();
            $table->string('customer_relationship', 32)->nullable();
            $table->string('customer_contact_channel', 16)->nullable();
            $table->timestamp('privacy_notice_accepted_at')->nullable();

            $table->string('deceased_full_name', 191)->nullable();
            $table->date('deceased_date_of_birth')->nullable();
            $table->date('deceased_date_of_death')->nullable();
            $table->string('deceased_relationship', 32)->nullable();
            $table->string('deceased_gender', 16)->nullable();

            $table->string('document_ktp_path', 500)->nullable();
            $table->string('document_kk_path', 500)->nullable();
            $table->string('document_death_certificate_path', 500)->nullable();

            $table->string('payment_method', 32)->nullable();
            $table->string('payment_reference', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_drafts', function (Blueprint $table): void {
            $table->dropColumns([
                'customer_full_name',
                'customer_mobile',
                'customer_email',
                'customer_address',
                'customer_relationship',
                'customer_contact_channel',
                'privacy_notice_accepted_at',
                'deceased_full_name',
                'deceased_date_of_birth',
                'deceased_date_of_death',
                'deceased_relationship',
                'deceased_gender',
                'document_ktp_path',
                'document_kk_path',
                'document_death_certificate_path',
                'payment_method',
                'payment_reference',
            ]);
        });
    }
};
