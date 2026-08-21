<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes a real schema mistake in the P5b CareSubscription/VendorFulfillment
 * lane: four columns that hold an authenticated user's identity
 * (`subscriptions.customer_id`, `service_acceptances.customer_id`,
 * `service_complaints.customer_id`, `work_evidence.uploaded_by`) were typed
 * `uuid`, but `users.id` is a plain bigint, and nothing in this codebase
 * ever minted a UUID "customer" identity to populate them with. Confirmed
 * this is not a deliberate identity-architecture decision:
 *
 *   - None of the four columns carried a foreign key constraint or a
 *     design-rationale comment explaining a UUID choice.
 *   - No `Customer` model/concept exists anywhere in this codebase.
 *   - The one real write path that predates this fix
 *     (`App\Filament\Admin\Resources\Subscriptions\Actions\
 *     CreateSubscriptionAction`) already wrote
 *     `(string) $actor->identityReference` -- a bigint cast to string --
 *     into the uuid column, which is silently accepted by SQLite (this
 *     app's PHPUnit driver) but throws `SQLSTATE[22P02]: invalid input
 *     syntax for type uuid` on real Postgres (confirmed directly against a
 *     disposable Postgres 18 container, 20 Aug 2026 -- see
 *     `CareHistoryPage`'s pre-fix doc block for the full incident).
 *   - Every other place in this codebase that references a user uses the
 *     established, real convention: a plain bigint FK
 *     (`booking_drafts.user_id`, `order_parties.user_id` both use
 *     `foreignId('user_id')->constrained('users')`).
 *
 * This migration brings all four columns in line with that established
 * convention. New migration, not an edit to the four original create-table
 * migrations, because those have already run against the shared dev/
 * staging/beta database this session redeployed against -- editing them in
 * place would not apply on any host where they already ran.
 *
 * Zero production-data risk: confirmed via `git log`/`grep` that no seed
 * migration or fixture ever inserted a row into any of these four tables --
 * they are schema-only since creation (17 Aug 2026), never populated with
 * real data before this fix.
 *
 * Nullability matches each original column exactly (none were nullable,
 * despite `App\Domain\VendorFulfillment\Actions\UploadEvidence`'s
 * `?string $uploadedBy = null` signature allowing null in code -- that
 * pre-existing code/schema mismatch is unrelated to this fix and is left
 * as-is rather than silently widened here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_customer_index');
            $table->dropColumn('customer_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('customer_id')->after('care_plan_id')->constrained('users');
            $table->index(['customer_id'], 'subscriptions_customer_index');
        });

        Schema::table('service_acceptances', function (Blueprint $table) {
            $table->dropColumn('customer_id');
        });

        Schema::table('service_acceptances', function (Blueprint $table) {
            $table->foreignId('customer_id')->after('work_order_id')->constrained('users');
        });

        Schema::table('service_complaints', function (Blueprint $table) {
            $table->dropColumn('customer_id');
        });

        Schema::table('service_complaints', function (Blueprint $table) {
            $table->foreignId('customer_id')->after('work_order_id')->constrained('users');
        });

        Schema::table('work_evidence', function (Blueprint $table) {
            $table->dropColumn('uploaded_by');
        });

        Schema::table('work_evidence', function (Blueprint $table) {
            $table->foreignId('uploaded_by')->after('evidence_type')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->uuid('customer_id')->after('care_plan_id');
            $table->index(['customer_id'], 'subscriptions_customer_index');
        });

        Schema::table('service_acceptances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::table('service_acceptances', function (Blueprint $table) {
            $table->uuid('customer_id')->after('work_order_id');
        });

        Schema::table('service_complaints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::table('service_complaints', function (Blueprint $table) {
            $table->uuid('customer_id')->after('work_order_id');
        });

        Schema::table('work_evidence', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uploaded_by');
        });

        Schema::table('work_evidence', function (Blueprint $table) {
            $table->uuid('uploaded_by')->after('evidence_type');
        });
    }
};
