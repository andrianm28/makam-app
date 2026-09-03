<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DemoDataBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan demo-data:purge {batchId?} --force`
 *
 * Removes every row `demo-data:seed` (Task 10) wrote for one batch,
 * defaulting to the most recently seeded batch when no id is given.
 *
 * ---------------------------------------------------------------------------
 * Two kinds of tables this subsystem writes to — only one kind is tagged
 * ---------------------------------------------------------------------------
 * `2026_09_03_150000_add_demo_batch_id_for_demo_seed_data.php` adds
 * `demo_batch_id` to a SUBSET of the tables the generators write to —
 * `DELETE_ORDER_*` below lists exactly that subset, confirmed against that
 * migration's own `TABLES` constant. The rest have no such column at all
 * and are purged by walking a real FK chain from an already-tagged parent:
 *
 *   - `vendor_listings`/`service_areas` — via `vendors.id` (see
 *     `deleteVendorScopedTables()`).
 *   - `marketplace_order_items`/`vendor_order_evidences` — via
 *     `marketplace_orders.id`/`vendor_orders.id` (see
 *     `deleteOrderScopedTables()`).
 *   - The care-subscription/vendor-fulfillment child chain
 *     (`subscription_cycles`, `subscription_invoices`,
 *     `subscription_payment_references`, `work_orders`, `work_order_tasks`,
 *     `work_evidence`, `service_acceptances`, `service_complaints`) — via
 *     `care_plans.id`/`subscriptions.id` (see
 *     `deleteCareSubscriptionScopedTables()`).
 *   - `order_status_events`/`order_parties`/`quotes`(+`quote_lines`)/
 *     `order_invoices`/`funeral_cases` — via `orders.id` (see
 *     `deleteBookingOrderScopedTables()`). NONE of this group is named
 *     anywhere in this task's own brief — every one of these tables is a
 *     real `restrictOnDelete()` (or, for `funeral_cases`, an unconstrained
 *     but still real) child of `orders` that the real `OrderWorkflow`
 *     Actions `BookingOrderExampleData` drives (`RecordOrderStatusChange`,
 *     `IssueOrderQuote`, `SubmitBookingDraft`, ...) write to, discovered
 *     empirically by diffing every `public` table's row count before/after
 *     a real `demo-data:seed` run rather than by reading each Action's
 *     source — the same class of gap Tasks 7/8 already found for the
 *     vendor-listing and care-subscription chains, just missed for
 *     `orders` when this plan was written.
 *   - `renewal_quotes`/`renewal_external_markings` — via `renewals.id`
 *     (see `deleteRenewalScopedTables()`), found the same empirical way.
 *
 * `vendor_payables.vendor_id` is a plain string column with no DB-level FK
 * (confirmed against
 * `2026_08_09_120000_create_vendor_payables_table.php`) — it never blocks
 * a delete, so it is purged as a courtesy cleanup (matched against tagged
 * `vendors.id`) rather than a FK-chain necessity; see
 * `deleteVendorScopedTables()`.
 *
 * `visitation_date_capacities.policy_id` DOES `cascadeOnDelete()` on
 * `cemetery_visitation_policies` (confirmed against
 * `2026_08_16_110030_create_visitation_date_capacities_table.php`) — no
 * explicit code purges it; deleting the tagged `cemetery_visitation_policies`
 * row in `DELETE_ORDER_BEFORE_VENDOR_CHILDREN` removes it automatically.
 *
 * `audit_events`/`outbox_events` carry only plain string references
 * (confirmed against their own migrations), never a real FK to anything
 * this command deletes — left untouched deliberately, the same way a
 * production audit trail is never deleted by a data-cleanup operation.
 *
 * `cart_items`/`carts` are deliberately NOT purged — also untagged, but
 * harmless to leave behind: `PlaceMarketplaceOrder` already empties a
 * cart's items and vendor lock once an order is placed, so by the time
 * purge runs, this subsystem's demo carts are inert rows carrying only a
 * batch-scoped `customer_ref` string, never rendered or referenced by
 * anything else this command removes.
 *
 * Every table in `DELETE_ORDER_*` genuinely has the `demo_batch_id`
 * column — that array must never grow a table that doesn't, or the
 * uniform loop below throws a real "column does not exist" error and
 * aborts the whole purge inside its one transaction. `vendor_availability`
 * is a real example of the trap: no generator in this subsystem writes to
 * it, and it carries no `demo_batch_id` column (confirmed against
 * `2026_09_03_150000_add_demo_batch_id_for_demo_seed_data.php`'s `TABLES`
 * list), so it is not listed below at all.
 *
 * ---------------------------------------------------------------------------
 * Why the vendor-order/vendor-listing deletes are split into two passes
 * ---------------------------------------------------------------------------
 * `vendor_orders.listing_id` and `marketplace_order_items.vendor_listing_id`
 * both `restrictOnDelete()` on `vendor_listings`
 * (`2026_08_12_110000_create_vendor_orders_table.php`,
 * `2026_08_12_100080_create_marketplace_order_items_table.php`) — deleting
 * a demo vendor's `vendor_listings` row while a `vendor_orders`/
 * `marketplace_order_items` row still references it fails the whole
 * transaction. `deleteOrderScopedTables()` (which removes those two leaf
 * tables) and the `vendor_orders`/`marketplace_orders` deletes in
 * `DELETE_ORDER_BEFORE_VENDOR_CHILDREN` therefore both run BEFORE
 * `deleteVendorScopedTables()`, which is what actually removes
 * `vendor_listings`/`service_areas`.
 */
final class DemoDataPurgeCommand extends Command
{
    protected $signature = 'demo-data:purge {batchId?} {--force : Required. Purges without this flag are refused.}';

    protected $description = 'Remove every row tagged with a demo_batch_id, defaulting to the most recently seeded batch.';

    /**
     * Tagged tables safe to delete before `vendor_listings`/`service_areas`
     * are removed — none of these carries a `restrictOnDelete()` FK back to
     * a `vendor_listings`/`service_areas` row. `subscriptions`/`care_plans`
     * come first since `deleteCareSubscriptionScopedTables()` has already
     * cleared every untagged child referencing them by this point.
     *
     * @var list<string>
     */
    private const array DELETE_ORDER_BEFORE_VENDOR_CHILDREN = [
        'subscriptions', 'care_plans',
        'agreements', 'certificates', 'visitation_bookings', 'cemetery_visitation_policies',
        'vendor_orders', 'marketplace_orders',
    ];

    /**
     * Tagged tables deleted after `deleteVendorScopedTables()` has removed
     * `vendor_listings`/`service_areas` — `vendor_users`/`vendors` can only
     * be removed once nothing with a `restrictOnDelete()` FK still points
     * at them.
     *
     * @var list<string>
     */
    private const array DELETE_ORDER_AFTER_VENDOR_CHILDREN = [
        'vendor_users', 'vendors',
        'orders', 'booking_drafts', 'renewals',
        'scope_assignments', 'actor_role_assignments',
        'users',
    ];

    public function handle(): int
    {
        $batchId = $this->argument('batchId') ?? DemoDataBatch::query()->orderByDesc('created_at')->value('batch_id');

        if ($batchId === null) {
            $this->error('No demo data batch found to purge.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->error('Refusing to purge without --force.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($batchId): void {
            $this->deleteCareSubscriptionScopedTables($batchId);
            $this->deleteOrderScopedTables($batchId);
            $this->deleteBookingOrderScopedTables($batchId);
            $this->deleteRenewalScopedTables($batchId);

            foreach (self::DELETE_ORDER_BEFORE_VENDOR_CHILDREN as $table) {
                $this->deleteTagged($table, $batchId);
            }

            $this->deleteVendorScopedTables($batchId);

            foreach (self::DELETE_ORDER_AFTER_VENDOR_CHILDREN as $table) {
                $this->deleteTagged($table, $batchId);
            }

            DemoDataBatch::query()->where('batch_id', $batchId)->delete();
        });

        $this->info("Purged demo data batch: {$batchId}");

        return self::SUCCESS;
    }

    private function deleteTagged(string $table, string $batchId): void
    {
        $count = DB::table($table)->where('demo_batch_id', $batchId)->delete();

        if ($count > 0) {
            $this->line(sprintf('%-28s %d', $table, $count));
        }
    }

    /**
     * `vendor_listings` and `service_areas` have no `demo_batch_id` column
     * — both carry a `restrictOnDelete()` FK to `vendors` (which IS
     * tagged), so deleting them by `vendor_id` first is both the correct
     * removal path and a requirement of that FK regardless. Must run after
     * `vendor_orders`/`marketplace_order_items` have already been removed
     * — see this class's own doc block.
     */
    private function deleteVendorScopedTables(string $batchId): void
    {
        $vendorIds = DB::table('vendors')->where('demo_batch_id', $batchId)->pluck('id');

        if ($vendorIds->isEmpty()) {
            return;
        }

        foreach (['vendor_listings', 'service_areas'] as $table) {
            $count = DB::table($table)->whereIn('vendor_id', $vendorIds)->delete();

            if ($count > 0) {
                $this->line(sprintf('%-28s %d', $table, $count));
            }
        }

        // No DB-level FK — courtesy cleanup, not a constraint requirement.
        // See this class's own doc block.
        $count = DB::table('vendor_payables')->whereIn('vendor_id', $vendorIds->map(static fn ($id): string => (string) $id))->delete();
        if ($count > 0) {
            $this->line(sprintf('%-28s %d', 'vendor_payables', $count));
        }
    }

    /**
     * `order_status_events`, `order_parties`, `quotes` (and its own child
     * `quote_lines`), and `order_invoices` all `restrictOnDelete()` on
     * `orders` — confirmed against
     * `2026_08_12_100010_create_order_status_events_table.php`,
     * `2026_08_12_100020_create_order_parties_table.php`,
     * `2026_08_12_100040_create_quotes_table.php` (`quote_lines.quote_id`
     * restricts on `quotes` too, so it is deleted first),
     * `2026_08_26_100000_create_order_invoices_table.php`. None of the
     * four carries a `demo_batch_id` column.
     *
     * `funeral_cases` is different: `orders.funeral_case_id` has no DB-level
     * FK at all (confirmed: `2026_08_12_100000_create_orders_table.php`'s
     * own doc block — "no FK ... A later lane that creates those tables is
     * expected to add the constraint then, not this one"), so a demo
     * funeral case is looked up by reading the tagged orders' own
     * `funeral_case_id` values before they are deleted, not by an inbound
     * constraint. Deleting it never blocks on `orders` either way.
     *
     * `GrantOrderPaymentOpening` (called for the `paid`/`completed` demo
     * orders) also writes a `scope_assignments` row with
     * `entity_type = 'order'`, `entity_id = <order id>` — untagged, since
     * `scope_assignments`' own `demo_batch_id` is only ever set by the
     * generators themselves, not by this real domain Action. Found
     * empirically (the same row-count-diff technique that found the rest
     * of this method), not by reading `GrantOrderPaymentOpening` first.
     * Purged here by `entity_id`, never by the main `DELETE_ORDER_*`
     * loop's blanket `demo_batch_id` filter, which cannot see it.
     */
    private function deleteBookingOrderScopedTables(string $batchId): void
    {
        $orders = DB::table('orders')->where('demo_batch_id', $batchId)->get(['id', 'funeral_case_id']);
        $orderIds = $orders->pluck('id');

        if ($orderIds->isEmpty()) {
            return;
        }

        $quoteIds = DB::table('quotes')->whereIn('order_id', $orderIds)->pluck('id');

        if ($quoteIds->isNotEmpty()) {
            $count = DB::table('quote_lines')->whereIn('quote_id', $quoteIds)->delete();
            if ($count > 0) {
                $this->line(sprintf('%-28s %d', 'quote_lines', $count));
            }
        }

        foreach (['order_status_events', 'order_parties', 'quotes', 'order_invoices'] as $table) {
            $count = DB::table($table)->whereIn('order_id', $orderIds)->delete();
            if ($count > 0) {
                $this->line(sprintf('%-28s %d', $table, $count));
            }
        }

        $funeralCaseIds = $orders->pluck('funeral_case_id')->filter()->values();

        if ($funeralCaseIds->isNotEmpty()) {
            $count = DB::table('funeral_cases')->whereIn('id', $funeralCaseIds)->delete();
            if ($count > 0) {
                $this->line(sprintf('%-28s %d', 'funeral_cases', $count));
            }
        }

        $count = DB::table('scope_assignments')
            ->where('entity_type', 'order')
            ->whereIn('entity_id', $orderIds)
            ->delete();
        if ($count > 0) {
            $this->line(sprintf('%-28s %d', 'scope_assignments', $count));
        }
    }

    /**
     * `renewal_quotes` and `renewal_external_markings` both
     * `restrictOnDelete()` on `renewals` — confirmed against
     * `2026_08_12_100010_create_renewal_quotes_table.php` and
     * `2026_08_12_100020_create_renewal_external_markings_table.php`.
     * Neither carries a `demo_batch_id` column.
     */
    private function deleteRenewalScopedTables(string $batchId): void
    {
        $renewalIds = DB::table('renewals')->where('demo_batch_id', $batchId)->pluck('id');

        if ($renewalIds->isEmpty()) {
            return;
        }

        foreach (['renewal_quotes', 'renewal_external_markings'] as $table) {
            $count = DB::table($table)->whereIn('renewal_id', $renewalIds)->delete();
            if ($count > 0) {
                $this->line(sprintf('%-28s %d', $table, $count));
            }
        }
    }

    /**
     * `marketplace_order_items` and `vendor_order_evidences` have no
     * `demo_batch_id` column — the same untagged-child-table shape as
     * `vendor_listings`/`service_areas` above. Both real FK columns
     * confirmed against the migrations directly (not the schema-naming-
     * convention guess this plan's own draft carried before this task):
     * `marketplace_order_items.marketplace_order_id`
     * (`2026_08_12_100080_create_marketplace_order_items_table.php`) and
     * `vendor_order_evidences.vendor_order_id`
     * (`2026_08_12_110010_create_vendor_order_evidences_table.php`).
     */
    private function deleteOrderScopedTables(string $batchId): void
    {
        $marketplaceOrderIds = DB::table('marketplace_orders')->where('demo_batch_id', $batchId)->pluck('id');
        $vendorOrderIds = DB::table('vendor_orders')->where('demo_batch_id', $batchId)->pluck('id');

        if ($marketplaceOrderIds->isNotEmpty()) {
            $count = DB::table('marketplace_order_items')->whereIn('marketplace_order_id', $marketplaceOrderIds)->delete();
            if ($count > 0) {
                $this->line(sprintf('%-28s %d', 'marketplace_order_items', $count));
            }
        }

        if ($vendorOrderIds->isNotEmpty()) {
            $count = DB::table('vendor_order_evidences')->whereIn('vendor_order_id', $vendorOrderIds)->delete();
            if ($count > 0) {
                $this->line(sprintf('%-28s %d', 'vendor_order_evidences', $count));
            }
        }
    }

    /**
     * The care-subscription/vendor-fulfillment chain — the largest block of
     * untagged child tables in this subsystem. Only `care_plans` and
     * `subscriptions` (the two roots) are tagged; deletion walks the real
     * FK chain from them.
     *
     * `subscription_cycles.subscription_id` and `work_orders.care_plan_id`
     * are both confirmed FK-shaped columns (`work_orders.care_plan_id` is
     * a plain `foreignUuid()` with no `->constrained()`, so it carries no
     * actual DB constraint, but is still the correct real relationship to
     * walk). `subscription_cycles.invoice_id` (nullable, pointing AT
     * `subscription_invoices`) is confirmed too.
     *
     * `subscription_payment_references` FKs directly to `subscriptions`
     * via a `subscription_id` column
     * (`database/migrations/2026_08_17_110040_create_subscription_payment_references_table.php`:
     * `$table->uuid('subscription_id'); ... $table->foreign('subscription_id')->references('id')->on('subscriptions');`).
     * This plan's own draft code guessed `subscription_invoice_id` off
     * `subscription_invoices`, following this schema's `<parent>_id`
     * naming convention — that guess was wrong; the table has no
     * `subscription_invoice_id` column at all. Confirmed here by reading
     * the real migration, not the plan text.
     */
    private function deleteCareSubscriptionScopedTables(string $batchId): void
    {
        $carePlanIds = DB::table('care_plans')->where('demo_batch_id', $batchId)->pluck('id');
        $subscriptionIds = DB::table('subscriptions')->where('demo_batch_id', $batchId)->pluck('id');

        $cycleIds = $subscriptionIds->isEmpty()
            ? collect()
            : DB::table('subscription_cycles')->whereIn('subscription_id', $subscriptionIds)->pluck('id');
        $invoiceIds = $cycleIds->isEmpty()
            ? collect()
            : DB::table('subscription_cycles')->whereIn('id', $cycleIds)->whereNotNull('invoice_id')->pluck('invoice_id');
        $workOrderIds = $carePlanIds->isEmpty()
            ? collect()
            : DB::table('work_orders')->whereIn('care_plan_id', $carePlanIds)->pluck('id');

        foreach ([
            'work_evidence' => $workOrderIds, 'service_acceptances' => $workOrderIds,
            'service_complaints' => $workOrderIds, 'work_order_tasks' => $workOrderIds,
        ] as $table => $ids) {
            if ($ids->isEmpty()) {
                continue;
            }
            $count = DB::table($table)->whereIn('work_order_id', $ids)->delete();
            if ($count > 0) {
                $this->line(sprintf('%-28s %d', $table, $count));
            }
        }

        if ($workOrderIds->isNotEmpty()) {
            $count = DB::table('work_orders')->whereIn('id', $workOrderIds)->delete();
            if ($count > 0) {
                $this->line(sprintf('%-28s %d', 'work_orders', $count));
            }
        }

        if ($subscriptionIds->isNotEmpty()) {
            $count = DB::table('subscription_payment_references')->whereIn('subscription_id', $subscriptionIds)->delete();
            if ($count > 0) {
                $this->line(sprintf('%-28s %d', 'subscription_payment_references', $count));
            }
        }

        if ($invoiceIds->isNotEmpty()) {
            $count = DB::table('subscription_invoices')->whereIn('id', $invoiceIds)->delete();
            if ($count > 0) {
                $this->line(sprintf('%-28s %d', 'subscription_invoices', $count));
            }
        }

        if ($cycleIds->isNotEmpty()) {
            $count = DB::table('subscription_cycles')->whereIn('id', $cycleIds)->delete();
            if ($count > 0) {
                $this->line(sprintf('%-28s %d', 'subscription_cycles', $count));
            }
        }
    }
}
