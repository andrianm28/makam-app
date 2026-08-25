<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The single-vendor-per-order guarantee, enforced in the DATABASE (not just
 * in `PlaceMarketplaceOrder`), because requirement 4 is an MVP invariant and
 * requirement 14 forbids relaxing it until order splitting, partial
 * cancellation/refund, fee/tax allocation, dispute handling, and
 * reconciliation all exist.
 *
 * A deferred constraint trigger asserts each `marketplace_order` allocates
 * to AT MOST ONE vendor at COMMIT — deferred so `PlaceMarketplaceOrder` can
 * insert the order and its vendor-allocation rows in either sequence within
 * one transaction. The check is on the LINKED rows (`vendor_orders` with a
 * non-null `marketplace_order_id`, per Ruling 1): vendor orders that L10
 * writes without a marketplace-order link are outside the marketplace
 * checkout lifecycle and are not asserted.
 *
 * Model on `2026_08_10_120300_enforce_vendor_payable_payout_consistency.php`
 * (house pattern: `DROP TRIGGER/FUNCTION IF EXISTS` so the migration is
 * re-runnable, nowdoc `DB::unprepared`, `pgsql` early return, no-op `down()`
 * with written justification).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS vendor_orders_single_vendor ON vendor_orders;
            DROP FUNCTION IF EXISTS enforce_single_vendor_per_marketplace_order();
            DROP FUNCTION IF EXISTS assert_single_vendor_per_marketplace_order(uuid);

            CREATE FUNCTION assert_single_vendor_per_marketplace_order(p_order_id uuid)
            RETURNS void
            LANGUAGE plpgsql
            AS $function$
            DECLARE
                v_vendor_count integer;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM marketplace_orders WHERE id = p_order_id) THEN
                    RETURN; -- the order itself was deleted; nothing to assert
                END IF;

                SELECT COUNT(DISTINCT vendor_id) INTO v_vendor_count
                FROM vendor_orders WHERE marketplace_order_id = p_order_id;

                IF v_vendor_count > 1 THEN
                    RAISE EXCEPTION
                        'marketplace order % allocates to % vendors; MVP allows exactly one (requirement 4/14)',
                        p_order_id, v_vendor_count
                        USING ERRCODE = '23514';
                END IF;
            END;
            $function$;

            CREATE FUNCTION enforce_single_vendor_per_marketplace_order()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                PERFORM assert_single_vendor_per_marketplace_order(COALESCE(NEW.marketplace_order_id, OLD.marketplace_order_id));
                RETURN COALESCE(NEW, OLD);
            END;
            $function$;

            CREATE CONSTRAINT TRIGGER vendor_orders_single_vendor
            AFTER INSERT OR UPDATE OR DELETE ON vendor_orders
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION enforce_single_vendor_per_marketplace_order();
            SQL);
    }

    public function down(): void
    {
        // The single-vendor constraint is an MVP invariant (requirement 4/14).
        // Preserve it during an application rollback rather than reopening a
        // direct-write bypass — same disposition `2026_08_10_120300` applies.
    }
};
