<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds `feature_gates` and `feature_flags` from the two documents
 * requirements.md AC1 names as the single source of truth:
 * `docs/governance/assumptions-and-gates.md` §2 (17 gates) and §3 (18
 * flags), and `docs/operations/feature-flag-registry.md` (owner/default/
 * prerequisite per flag).
 *
 * This is a DATA migration, not a schema one — deliberately kept separate
 * from `..._120000_create_feature_gates_table.php` /
 * `..._120100_create_feature_flags_table.php` so a future re-seed (the
 * registry docs change) can be a new migration that only touches rows, not
 * a rewrite of table structure.
 *
 * ---------------------------------------------------------------------------
 * What is copied from the source docs, and why this is "implementing", not
 * "restating" (requirements.md AC1 / this batch's brief)
 * ---------------------------------------------------------------------------
 * Copied as data: gate id, the short "Capability" phrase, "Type", and (for
 * flags) flag key, default enabled/disabled, owner, prerequisite. These are
 * exactly the columns AC3 requires this system to record — putting them in
 * a table row IS the implementation the requirement asks for, not a rival
 * copy of the documentation. What this migration does NOT do: copy the
 * registry's free-text "Activation evidence" or "Behavior before active"
 * prose into any column or comment. `feature_gates.evidence_reference`,
 * `.effective_at`, and `.rollback_path` are left NULL for every row — see
 * "current state" below for why that is accurate, not an omission.
 *
 * ---------------------------------------------------------------------------
 * Current state seeded: every gate `closed`, every flag row present but
 * inert until a real per-environment override exists
 * ---------------------------------------------------------------------------
 * tasks.md's own NOT TESTED note for this spec: "Gate states are unknown —
 * G-PAY-01, G-WA-01, G-LEGAL-01, G-DATA-01 and the rest have no recorded
 * current value in the repository." No gate has a real activation evidence
 * reference to record, so none is seeded as `open`. This also matches
 * deny-by-default (AC10) as the honest starting posture for a registry
 * nothing has activated yet, not just the safe one.
 *
 * ---------------------------------------------------------------------------
 * Owner derivation for `feature_gates.owner` (nullable column)
 * ---------------------------------------------------------------------------
 * assumptions-and-gates.md's gate table (§2) has no "Owner" column of its
 * own. Where feature-flag-registry.md names exactly one flag whose
 * "Prerequisite" column is that single gate id, this migration copies that
 * flag's "Owner" value onto the gate row (both documents agree it is the
 * same capability). Left NULL where the registry does not support a single
 * unambiguous owner:
 *   - G-CAP-01, G-PLOT-01: `feature.plot_inventory`'s prerequisite is the
 *     pair "G-CAP-01/G-PLOT-01" (owner "Data owner"), while
 *     `feature.plot_reservation`'s prerequisite is G-PLOT-01 alone (owner
 *     "Operator/Engineering") — two different flags disagree on which of
 *     these two gates they gate, so neither gate gets a single owner value
 *     fabricated here.
 *   - G-RATE-01, G-EXT-01: no flag in feature-flag-registry.md names either
 *     as a prerequisite at all.
 * `feature.grave_search` (owner "Data owner") and `feature.grave_reminders`
 * (owner "Data owner/Operations") both name G-DATA-01; this migration uses
 * "Data owner" (the substring both agree on) for the gate row rather than
 * inventing a merged string, and keeps each flag row's own fuller owner
 * text as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // --- feature_gates — assumptions-and-gates.md §2, in the source
        // table's own row order. Owner per the derivation note above.
        $gates = [
            ['G-LEGAL-01', 'Paid Pre-Need plot purchase', 'Legal/financial', 'Legal/Finance'],
            ['G-PROTECTION-01', 'Funeral protection membership', 'Structural/regulatory', 'Legal/Product'],
            ['G-LAND-01', 'Land/plot marketplace or rights transfer', 'Legal', 'Legal/Product'],
            ['G-OPS-01', 'Urgent/At-Need acceptance', 'Operational', 'Operations/Product'],
            ['G-CAP-01', 'Cemetery capability profile', 'Governance', null],
            ['G-PLOT-01', 'Specific plot inventory/reservation', 'Data/integration', null],
            ['G-DIRECT-01', 'Direct plot purchase', 'Structural', 'Legal/Finance'],
            ['G-PAY-01', 'Online payment', 'Activation', 'Finance/Engineering'],
            ['G-PAYOUT-01', 'Automated vendor payout', 'Activation', 'Finance'],
            ['G-TOKEN-01', 'Card-on-file', 'Activation', 'Finance/Security'],
            ['G-WA-01', 'WhatsApp', 'Activation', 'Operations'],
            ['G-DATA-01', 'Grave search/reminder', 'Data/privacy', 'Data owner'],
            ['G-MEM-01', 'Public memorial/QR', 'Privacy/moderation', 'Privacy/Product'],
            ['G-VISIT-01', 'Bookable visitation', 'Operational', 'Operator'],
            ['G-CERT-01', 'Platform-issued certificates', 'Legal/operational', 'Legal/Operations'],
            ['G-RATE-01', 'Renewal tariff/fine', 'Structural', null],
            ['G-EXT-01', 'Outside-system renewal marking', 'Structural/integration', null],
        ];

        foreach ($gates as [$gateId, $capability, $type, $owner]) {
            DB::table('feature_gates')->insert([
                'gate_id' => $gateId,
                'capability' => $capability,
                'type' => $type,
                'owner' => $owner,
                'state' => 'closed',
                'evidence_reference' => null,
                'effective_at' => null,
                'rollback_path' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // --- feature_flags — feature-flag-registry.md, in the source
        // table's own row order. `default_enabled` mirrors that document's
        // "Default" column literally (only `preneed_interest` is On).
        // `prerequisite_gate_id` set when the "Prerequisite" column names
        // exactly one G-* id; `prerequisite_note` carries the registry's
        // own text verbatim for the two rows that do not (see class doc
        // block).
        $flags = [
            ['feature.urgent_booking', false, 'Operations/Product', 'G-OPS-01', null],
            ['feature.preneed_interest', true, 'Product', null, 'Approved interest flow'],
            ['feature.preneed_payment', false, 'Legal/Finance', 'G-LEGAL-01', null],
            ['feature.funeral_protection', false, 'Legal/Product', 'G-PROTECTION-01', null],
            ['feature.land_marketplace', false, 'Legal/Product', 'G-LAND-01', null],
            ['feature.online_payment', false, 'Finance/Engineering', 'G-PAY-01', null],
            ['feature.plot_inventory', false, 'Data owner', null, 'G-CAP-01/G-PLOT-01'],
            ['feature.plot_reservation', false, 'Operator/Engineering', 'G-PLOT-01', null],
            ['feature.direct_plot_purchase', false, 'Legal/Finance', 'G-DIRECT-01', null],
            ['feature.platform_certificate', false, 'Legal/Operations', 'G-CERT-01', null],
            ['feature.visitation_booking', false, 'Operator', 'G-VISIT-01', null],
            ['feature.memorial_public', false, 'Privacy/Product', 'G-MEM-01', null],
            ['feature.memorial_qr', false, 'Privacy/Product', 'G-MEM-01', null],
            ['feature.vendor_auto_payout', false, 'Finance', 'G-PAYOUT-01', null],
            ['feature.subscription_tokenization', false, 'Finance/Security', 'G-TOKEN-01', null],
            ['feature.whatsapp', false, 'Operations', 'G-WA-01', null],
            ['feature.grave_search', false, 'Data owner', 'G-DATA-01', null],
            ['feature.grave_reminders', false, 'Data owner/Operations', 'G-DATA-01', null],
        ];

        foreach ($flags as [$flagKey, $defaultEnabled, $owner, $gateId, $note]) {
            DB::table('feature_flags')->insert([
                'flag_key' => $flagKey,
                'default_enabled' => $defaultEnabled,
                'owner' => $owner,
                'prerequisite_gate_id' => $gateId,
                'prerequisite_note' => $note,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('feature_flags')->whereIn('flag_key', [
            'feature.urgent_booking', 'feature.preneed_interest', 'feature.preneed_payment',
            'feature.funeral_protection', 'feature.land_marketplace', 'feature.online_payment',
            'feature.plot_inventory', 'feature.plot_reservation', 'feature.direct_plot_purchase',
            'feature.platform_certificate', 'feature.visitation_booking', 'feature.memorial_public',
            'feature.memorial_qr', 'feature.vendor_auto_payout', 'feature.subscription_tokenization',
            'feature.whatsapp', 'feature.grave_search', 'feature.grave_reminders',
        ])->delete();

        DB::table('feature_gates')->whereIn('gate_id', [
            'G-LEGAL-01', 'G-PROTECTION-01', 'G-LAND-01', 'G-OPS-01', 'G-CAP-01',
            'G-PLOT-01', 'G-DIRECT-01', 'G-PAY-01', 'G-PAYOUT-01', 'G-TOKEN-01',
            'G-WA-01', 'G-DATA-01', 'G-MEM-01', 'G-VISIT-01', 'G-CERT-01',
            'G-RATE-01', 'G-EXT-01',
        ])->delete();
    }
};
