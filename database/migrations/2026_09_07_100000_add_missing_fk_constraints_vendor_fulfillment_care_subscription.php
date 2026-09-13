<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-03 (batch M3a) — the VendorFulfillment/CareSubscription tables
 * declared `foreignUuid()`/`uuid()` FK-shaped columns with no
 * `->constrained()` at all when they were first created
 * (`2026_08_17_120000_create_work_orders_table.php`,
 * `2026_08_17_120010_create_work_order_tasks_table.php`,
 * `2026_08_17_120020_create_work_evidence_table.php`, and several more
 * confirmed by grepping every `2026_08_17_*` migration in this family —
 * see the table below). That whole module has zero database-level
 * referential integrity: nothing stops a row from pointing at a
 * `work_order_id`/`care_plan_id`/`vendor_id`/... that does not exist.
 *
 * Expand-only, NEW migration — the three create-table migrations named
 * above (and the others in the table below) are not edited in place: they
 * may already be applied in deployed environments (dev/staging/beta), and
 * this repository's migration hygiene (`AGENTS.md` §Database) never
 * rewrites an already-shipped migration.
 *
 * `customer_id` (×3: `subscriptions`, `service_acceptances`,
 * `service_complaints`) and `work_evidence.uploaded_by` are NOT listed
 * below — `2026_08_22_100000_fix_customer_and_uploader_identity_columns.php`
 * already gave those a real `foreignId(...)->constrained('users')` FK.
 * `service_complaints.make_good_order_id` is also already constrained
 * (`2026_09_04_100000_add_make_good_order_id_to_service_complaints.php`).
 * `pre_need_consultation_requests.pre_need_interest_id` already carries
 * `->constrained()->nullOnDelete()` and is a different domain
 * (PreNeed) — out of scope here.
 *
 * ---------------------------------------------------------------------------
 * Constraints added, and why each `On delete` choice
 * ---------------------------------------------------------------------------
 *   work_orders.care_plan_id            -> care_plans.id            RESTRICT
 *   work_orders.subscription_cycle_id   -> subscription_cycles.id   RESTRICT (nullable)
 *   work_orders.vendor_id               -> vendors.id               RESTRICT (nullable)
 *   work_orders.assigned_to             -> vendors.id               RESTRICT (nullable;
 *       confirmed via `AssignWorkOrder`: this column is set to the SAME
 *       vendor id as `vendor_id`, not a user id — `assigned_to` names a
 *       vendor, not a person)
 *   work_order_tasks.work_order_id      -> work_orders.id           CASCADE
 *   work_evidence.work_order_id         -> work_orders.id           CASCADE
 *   work_evidence.document_id           -> documents.id             RESTRICT
 *   service_acceptances.work_order_id   -> work_orders.id           CASCADE
 *   service_complaints.work_order_id    -> work_orders.id           CASCADE
 *   make_good_orders.original_work_order_id      -> work_orders.id  RESTRICT
 *   make_good_orders.replacement_work_order_id   -> work_orders.id  RESTRICT (nullable)
 *   make_good_orders.original_cycle_id  -> subscription_cycles.id   RESTRICT
 *   care_plans.vendor_id                -> vendors.id               RESTRICT (nullable)
 *   subscription_cycles.work_order_id   -> work_orders.id           SET NULL (nullable;
 *       confirmed via `CreateWorkOrderFromCycle` that no code path ever
 *       writes this column — dead/unused today, safe to constrain)
 *   subscription_cycles.invoice_id      -> subscription_invoices.id SET NULL (nullable)
 *
 * `subscription_cycles.work_order_id`/`invoice_id` are SET NULL, not
 * RESTRICT, unlike everything else in this migration — deliberately, and
 * confirmed the hard way against a real disposable Postgres 18 database
 * before landing on it. `subscription_invoices.subscription_cycle_id`
 * already RESTRICTs on `subscription_cycles` (the invoice is the cycle's
 * real child); adding a SECOND, opposite-direction RESTRICT from
 * `subscription_cycles.invoice_id` back onto `subscription_invoices`
 * creates a genuine circular FK deadlock — `DemoDataPurgeCommand` deletes
 * `subscription_invoices` before `subscription_cycles` (correct, since
 * invoices are the real child), and a RESTRICT here made THAT delete fail
 * instead ("update or delete on table subscription_invoices violates
 * RESTRICT... referenced from table subscription_cycles"). SET NULL
 * breaks the cycle safely: deleting an invoice/work-order clears the
 * cycle's own denormalized pointer to it rather than blocking the delete
 * — correct for a column nothing currently even writes.
 *
 * Deliberately NOT included: `subscriptions.grave_id` -> `grave_records.id`.
 * It genuinely is an unconstrained FK-shaped column in this same table
 * family, but `grave_records` is a different domain (GraveRegistry) that
 * several existing degradation tests (`LaunchCityTest`,
 * `BookingWizardRouteTest`, `RenewalStartTest`,
 * `CemeteryDirectoryIndexRouteTest`) `Schema::dropIfExists('grave_records')`
 * as part of a carefully reverse-dependency-ordered drop list simulating a
 * failed read — none of them drop `subscriptions` first. Adding a REAL
 * `grave_id` FK there would make every one of those `DROP TABLE
 * grave_records` calls fail with Postgres's 2BP01 ("cannot drop table
 * because other objects depend on it"), for a column this task did not
 * explicitly name. Left as a follow-up for whoever owns that test suite's
 * next constraint pass rather than fixed here as a drive-by that breaks
 * four unrelated, carefully-maintained tests.
 *
 * CASCADE only for rows that exist solely as one work order's own children
 * and have no independent meaning once it is gone (`work_order_tasks`,
 * `work_evidence`, `service_acceptances`, `service_complaints` all key
 * directly off `work_order_id`). Everything else RESTRICTs — this
 * repository's established convention (see `2026_08_25_140000_seed_
 * realistic_marketplace_pricing_fixtures.php`'s own doc block on why a
 * blanket destructive cascade/truncate is avoided) is to never let a
 * schema-level FK silently destroy history; a real delete of a `vendor`,
 * `care_plan`, `subscription_cycle`, `document`, or `grave_record` that
 * still has fulfillment history attached should fail loudly, not quietly
 * cascade.
 *
 * ---------------------------------------------------------------------------
 * Orphan check — a query for a human to run, NOT an automated cleanup
 * ---------------------------------------------------------------------------
 * Per `AGENTS.md` §Database and §Infrastructure-agent execution, this
 * migration does NOT delete orphaned rows itself: an unattended DELETE
 * inside a migration that later runs against beta/production is exactly
 * the kind of destructive-migration-adjacent change that needs a human's
 * eyes FIRST, not a silent automatic cleanup. Instead:
 *
 *   - Verified zero orphans against a disposable Postgres 18 test database
 *     (fresh schema; this migration ran cleanly with no pre-existing rows
 *     in this table family).
 *   - If real orphans exist in an environment, `ALTER TABLE ... ADD
 *     CONSTRAINT` fails loudly and the whole migration rolls back — a safe
 *     failure mode that blocks the deploy rather than destroying data.
 *   - Before running this migration against beta/production, a human
 *     operator should run the following (adjust child/parent per row in
 *     the table above) to find any orphan BEFORE the migration is applied:
 *
 *     SELECT wo.id FROM work_orders wo
 *       LEFT JOIN care_plans cp ON cp.id = wo.care_plan_id
 *       WHERE cp.id IS NULL;
 *     SELECT wot.id FROM work_order_tasks wot
 *       LEFT JOIN work_orders wo ON wo.id = wot.work_order_id
 *       WHERE wo.id IS NULL;
 *     SELECT we.id FROM work_evidence we
 *       LEFT JOIN work_orders wo ON wo.id = we.work_order_id
 *       WHERE wo.id IS NULL;
 *     SELECT we.id FROM work_evidence we
 *       LEFT JOIN documents d ON d.id = we.document_id
 *       WHERE d.id IS NULL;
 *     SELECT sa.id FROM service_acceptances sa
 *       LEFT JOIN work_orders wo ON wo.id = sa.work_order_id
 *       WHERE wo.id IS NULL;
 *     SELECT sc.id FROM service_complaints sc
 *       LEFT JOIN work_orders wo ON wo.id = sc.work_order_id
 *       WHERE wo.id IS NULL;
 *     SELECT mgo.id FROM make_good_orders mgo
 *       LEFT JOIN work_orders wo ON wo.id = mgo.original_work_order_id
 *       WHERE wo.id IS NULL;
 *     SELECT mgo.id FROM make_good_orders mgo
 *       LEFT JOIN work_orders wo ON wo.id = mgo.replacement_work_order_id
 *       WHERE mgo.replacement_work_order_id IS NOT NULL AND wo.id IS NULL;
 *     SELECT mgo.id FROM make_good_orders mgo
 *       LEFT JOIN subscription_cycles sc ON sc.id = mgo.original_cycle_id
 *       WHERE sc.id IS NULL;
 *     SELECT cp.id FROM care_plans cp
 *       LEFT JOIN vendors v ON v.id = cp.vendor_id
 *       WHERE cp.vendor_id IS NOT NULL AND v.id IS NULL;
 *     SELECT sc.id FROM subscription_cycles sc
 *       LEFT JOIN work_orders wo ON wo.id = sc.work_order_id
 *       WHERE sc.work_order_id IS NOT NULL AND wo.id IS NULL;
 *     SELECT sc.id FROM subscription_cycles sc
 *       LEFT JOIN subscription_invoices si ON si.id = sc.invoice_id
 *       WHERE sc.invoice_id IS NOT NULL AND si.id IS NULL;
 *
 *     Any row returned must be fixed (repoint or remove, per the operator's
 *     judgement of what happened) BEFORE this migration is deployed there
 *     — this PR does not run any of the above against real data.
 *
 * `down()` only drops the constraints this migration adds — no column or
 * table is touched, so it is always safe to roll back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->foreign('care_plan_id', 'work_orders_care_plan_id_fk')
                ->references('id')->on('care_plans')->restrictOnDelete();
            $table->foreign('subscription_cycle_id', 'work_orders_subscription_cycle_id_fk')
                ->references('id')->on('subscription_cycles')->restrictOnDelete();
            $table->foreign('vendor_id', 'work_orders_vendor_id_fk')
                ->references('id')->on('vendors')->restrictOnDelete();
            $table->foreign('assigned_to', 'work_orders_assigned_to_fk')
                ->references('id')->on('vendors')->restrictOnDelete();
        });

        Schema::table('work_order_tasks', function (Blueprint $table): void {
            $table->foreign('work_order_id', 'work_order_tasks_work_order_id_fk')
                ->references('id')->on('work_orders')->cascadeOnDelete();
        });

        Schema::table('work_evidence', function (Blueprint $table): void {
            $table->foreign('work_order_id', 'work_evidence_work_order_id_fk')
                ->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('document_id', 'work_evidence_document_id_fk')
                ->references('id')->on('documents')->restrictOnDelete();
        });

        Schema::table('service_acceptances', function (Blueprint $table): void {
            $table->foreign('work_order_id', 'service_acceptances_work_order_id_fk')
                ->references('id')->on('work_orders')->cascadeOnDelete();
        });

        Schema::table('service_complaints', function (Blueprint $table): void {
            $table->foreign('work_order_id', 'service_complaints_work_order_id_fk')
                ->references('id')->on('work_orders')->cascadeOnDelete();
        });

        Schema::table('make_good_orders', function (Blueprint $table): void {
            $table->foreign('original_work_order_id', 'make_good_orders_original_work_order_id_fk')
                ->references('id')->on('work_orders')->restrictOnDelete();
            $table->foreign('replacement_work_order_id', 'make_good_orders_replacement_work_order_id_fk')
                ->references('id')->on('work_orders')->restrictOnDelete();
            $table->foreign('original_cycle_id', 'make_good_orders_original_cycle_id_fk')
                ->references('id')->on('subscription_cycles')->restrictOnDelete();
        });

        Schema::table('care_plans', function (Blueprint $table): void {
            $table->foreign('vendor_id', 'care_plans_vendor_id_fk')
                ->references('id')->on('vendors')->restrictOnDelete();
        });

        Schema::table('subscription_cycles', function (Blueprint $table): void {
            $table->foreign('work_order_id', 'subscription_cycles_work_order_id_fk')
                ->references('id')->on('work_orders')->nullOnDelete();
            $table->foreign('invoice_id', 'subscription_cycles_invoice_id_fk')
                ->references('id')->on('subscription_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_cycles', function (Blueprint $table): void {
            $table->dropForeign('subscription_cycles_work_order_id_fk');
            $table->dropForeign('subscription_cycles_invoice_id_fk');
        });

        Schema::table('care_plans', function (Blueprint $table): void {
            $table->dropForeign('care_plans_vendor_id_fk');
        });

        Schema::table('make_good_orders', function (Blueprint $table): void {
            $table->dropForeign('make_good_orders_original_work_order_id_fk');
            $table->dropForeign('make_good_orders_replacement_work_order_id_fk');
            $table->dropForeign('make_good_orders_original_cycle_id_fk');
        });

        Schema::table('service_complaints', function (Blueprint $table): void {
            $table->dropForeign('service_complaints_work_order_id_fk');
        });

        Schema::table('service_acceptances', function (Blueprint $table): void {
            $table->dropForeign('service_acceptances_work_order_id_fk');
        });

        Schema::table('work_evidence', function (Blueprint $table): void {
            $table->dropForeign('work_evidence_work_order_id_fk');
            $table->dropForeign('work_evidence_document_id_fk');
        });

        Schema::table('work_order_tasks', function (Blueprint $table): void {
            $table->dropForeign('work_order_tasks_work_order_id_fk');
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropForeign('work_orders_care_plan_id_fk');
            $table->dropForeign('work_orders_subscription_cycle_id_fk');
            $table->dropForeign('work_orders_vendor_id_fk');
            $table->dropForeign('work_orders_assigned_to_fk');
        });
    }
};
