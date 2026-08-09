<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Strengthen the cross-table payout invariant after the initial workflow
 * tables were introduced. Deferred constraint triggers allow the approved
 * payout transaction to insert its proof row before marking the payable paid,
 * while making the final state impossible to leave inconsistent through a
 * direct insert, update, delete, or reassignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE vendor_payables DROP CONSTRAINT IF EXISTS vendor_payables_amount_minor_check');
        DB::statement(
            'ALTER TABLE vendor_payables ADD CONSTRAINT vendor_payables_amount_minor_check CHECK (amount_minor > 0)'
        );

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payouts_match_vendor_payable ON payouts;
            DROP FUNCTION IF EXISTS assert_payout_matches_vendor_payable();
            DROP TRIGGER IF EXISTS vendor_payable_has_payout_state ON vendor_payables;
            DROP FUNCTION IF EXISTS assert_vendor_payable_has_payout_state();
            DROP TRIGGER IF EXISTS vendor_payables_payout_consistency ON vendor_payables;
            DROP TRIGGER IF EXISTS payouts_payable_consistency ON payouts;
            DROP FUNCTION IF EXISTS enforce_vendor_payable_payout_consistency();
            DROP FUNCTION IF EXISTS assert_vendor_payable_payout_pair(uuid);

            CREATE FUNCTION assert_vendor_payable_payout_pair(p_payable_id uuid)
            RETURNS void
            LANGUAGE plpgsql
            AS $function$
            DECLARE
                payable_vendor_id text;
                payable_entity_ref text;
                payable_amount_minor bigint;
                payable_state text;
                payout_count bigint;
                payout_vendor_id text;
                payout_entity_ref text;
                payout_amount_minor bigint;
                payout_state text;
                payout_proof_kind text;
                payout_proof_ref text;
            BEGIN
                SELECT vendor_id, entity_ref, amount_minor, state
                INTO payable_vendor_id, payable_entity_ref, payable_amount_minor, payable_state
                FROM vendor_payables
                WHERE id = p_payable_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Vendor payable % does not exist', p_payable_id
                        USING ERRCODE = '23503';
                END IF;

                SELECT COUNT(*)
                INTO payout_count
                FROM payouts
                WHERE payable_id = p_payable_id;

                IF payable_state = 'paid' AND payout_count <> 1 THEN
                    RAISE EXCEPTION 'Paid vendor payable % must have exactly one payout row', p_payable_id
                        USING ERRCODE = '23514';
                END IF;

                IF payable_state <> 'paid' AND payout_count <> 0 THEN
                    RAISE EXCEPTION 'Vendor payable % with a payout row must be paid', p_payable_id
                        USING ERRCODE = '23514';
                END IF;

                IF payout_count = 1 THEN
                    SELECT vendor_id, entity_ref, amount_minor, state,
                           proof_document_kind, proof_document_ref
                    INTO payout_vendor_id, payout_entity_ref, payout_amount_minor,
                         payout_state, payout_proof_kind, payout_proof_ref
                    FROM payouts
                    WHERE payable_id = p_payable_id;

                    IF payout_state <> 'recorded'
                        OR COALESCE(length(btrim(payout_proof_kind)), 0) = 0
                        OR COALESCE(length(btrim(payout_proof_ref)), 0) = 0
                        OR payout_vendor_id IS DISTINCT FROM payable_vendor_id
                        OR payout_entity_ref IS DISTINCT FROM payable_entity_ref
                        OR payout_amount_minor IS DISTINCT FROM payable_amount_minor THEN
                        RAISE EXCEPTION 'Payout for vendor payable % does not match its proof, state, or value', p_payable_id
                            USING ERRCODE = '23514';
                    END IF;
                END IF;
            END;
            $function$;

            CREATE FUNCTION enforce_vendor_payable_payout_consistency()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                IF TG_TABLE_NAME = 'vendor_payables' THEN
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM assert_vendor_payable_payout_pair(OLD.id);
                    END IF;

                    IF TG_OP <> 'DELETE' THEN
                        PERFORM assert_vendor_payable_payout_pair(NEW.id);
                    END IF;
                ELSE
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM assert_vendor_payable_payout_pair(OLD.payable_id);
                    END IF;

                    IF TG_OP <> 'DELETE' THEN
                        PERFORM assert_vendor_payable_payout_pair(NEW.payable_id);
                    END IF;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $function$;

            CREATE CONSTRAINT TRIGGER vendor_payables_payout_consistency
            AFTER INSERT OR UPDATE OR DELETE ON vendor_payables
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION enforce_vendor_payable_payout_consistency();

            CREATE CONSTRAINT TRIGGER payouts_payable_consistency
            AFTER INSERT OR UPDATE OR DELETE ON payouts
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION enforce_vendor_payable_payout_consistency();
            SQL);
    }

    public function down(): void
    {
        // The constraints are financial safeguards. Preserve them during an
        // application rollback rather than reopening direct-write bypasses.
    }
};
