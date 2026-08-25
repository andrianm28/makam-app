<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level payout safeguards that cannot be expressed by a plain
 * foreign key: positive amount, matching payable identity/value, and the
 * payable state transition paired with a payout row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE payouts DROP CONSTRAINT IF EXISTS payouts_amount_minor_check');
        DB::statement(
            'ALTER TABLE payouts ADD CONSTRAINT payouts_amount_minor_check CHECK (amount_minor > 0)'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_payout_matches_vendor_payable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            DECLARE
                payable_vendor_id text;
                payable_entity_ref text;
                payable_amount_minor bigint;
                payable_state text;
            BEGIN
                SELECT vendor_id, entity_ref, amount_minor, state
                INTO payable_vendor_id, payable_entity_ref, payable_amount_minor, payable_state
                FROM vendor_payables
                WHERE id = NEW.payable_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Payout payable % does not exist', NEW.payable_id
                        USING ERRCODE = '23503';
                END IF;

                IF payable_state <> 'payable' THEN
                    RAISE EXCEPTION 'Payout payable % is not in payable state', NEW.payable_id
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.vendor_id <> payable_vendor_id
                    OR NEW.entity_ref <> payable_entity_ref
                    OR NEW.amount_minor <> payable_amount_minor THEN
                    RAISE EXCEPTION 'Payout % does not match vendor payable %', NEW.id, NEW.payable_id
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $function$;

            DROP TRIGGER IF EXISTS payouts_match_vendor_payable ON payouts;
            CREATE TRIGGER payouts_match_vendor_payable
            BEFORE INSERT OR UPDATE OF payable_id, vendor_id, entity_ref, amount_minor ON payouts
            FOR EACH ROW EXECUTE FUNCTION assert_payout_matches_vendor_payable();

            CREATE OR REPLACE FUNCTION assert_vendor_payable_has_payout_state()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                IF NEW.state = 'paid'
                    AND NOT EXISTS (SELECT 1 FROM payouts WHERE payable_id = NEW.id) THEN
                    RAISE EXCEPTION 'Paid vendor payable % must have a payout row', NEW.id
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.state IN ('held', 'payable')
                    AND EXISTS (SELECT 1 FROM payouts WHERE payable_id = NEW.id) THEN
                    RAISE EXCEPTION 'Vendor payable % with a payout row must be paid', NEW.id
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $function$;

            DROP TRIGGER IF EXISTS vendor_payable_has_payout_state ON vendor_payables;
            CREATE TRIGGER vendor_payable_has_payout_state
            BEFORE INSERT OR UPDATE OF state ON vendor_payables
            FOR EACH ROW EXECUTE FUNCTION assert_vendor_payable_has_payout_state();
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payouts_match_vendor_payable ON payouts;
            DROP FUNCTION IF EXISTS assert_payout_matches_vendor_payable();
            DROP TRIGGER IF EXISTS vendor_payable_has_payout_state ON vendor_payables;
            DROP FUNCTION IF EXISTS assert_vendor_payable_has_payout_state();
            SQL);

        DB::statement('ALTER TABLE payouts DROP CONSTRAINT IF EXISTS payouts_amount_minor_check');
        DB::statement(
            'ALTER TABLE payouts ADD CONSTRAINT payouts_amount_minor_check CHECK (amount_minor >= 0)'
        );
    }
};
