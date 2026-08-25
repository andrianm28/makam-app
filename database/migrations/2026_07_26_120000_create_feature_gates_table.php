<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `feature_gates` — `platform-feature-gate` design.md's data list: "id,
 * capability, type, owner, state, effective_at, rollback_path".
 *
 * The 17 gate rows themselves (`G-LEGAL-01` … `G-EXT-01`) are seeded by
 * `2026_07_26_120400_seed_feature_gate_registry.php`, not this file — this
 * migration only creates the shape. See that seed migration's doc block for
 * exactly how each column's value is derived from
 * `docs/governance/assumptions-and-gates.md` and
 * `docs/operations/feature-flag-registry.md` (requirements.md AC1: those two
 * documents are the source of truth; this table implements them, it does not
 * restate their prose).
 *
 * `gate_id` (e.g. `G-PAY-01`) is the natural key end-to-end — the same
 * string a `FeatureGateResolver::isOpen()` caller passes in, the same string
 * `gate_activations.gate_id` and `gate_environment_state.gate_id` reference.
 * There is no separate surrogate integer id: nothing in this module ever
 * needs to look a gate up by anything other than its published id, and a
 * surrogate id would just be one more thing a misconfigured gate could get
 * wrong.
 *
 * `state` is intentionally a two-value column (`open`/`closed`), not three.
 * "Misconfigured" is never a value stored here — it is a resolution-time
 * diagnosis `FeatureGateResolver` makes when a row is missing, or `state`
 * holds anything other than those two values, or a required field the
 * resolver depends on cannot be read. See that class for the deny-by-default
 * mechanics (requirements.md AC10).
 *
 * Migration timestamp slot for this batch: 2026_07_26_120000 through
 * 2026_07_26_129999 (this batch's brief — see this batch's final report for
 * a flagged discrepancy against agent-execution-plan.md §5's own table,
 * which names a different slot for this same agent row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_gates', function (Blueprint $table) {
            $table->string('gate_id', 32)->primary();

            // Short capability label, taken verbatim from the "Capability"
            // column of assumptions-and-gates.md §2 — a single identifying
            // phrase, not the row's full prose (evidence/behaviour columns
            // are deliberately not duplicated into a text column here).
            $table->string('capability');

            $table->string('type');

            // Nullable: the source registry does not name a single owner
            // for every gate (see the seed migration's derivation notes for
            // which gates have no recorded owner today).
            $table->string('owner')->nullable();

            // 'open' | 'closed'. Validated by FeatureGateResolver, not by a
            // DB-level CHECK constraint — SQLite (this repo's test driver,
            // phpunit.xml) and the production Postgres driver do not share
            // one portable CHECK syntax, and the resolver already has to
            // treat an invalid value as "misconfigured -> closed" for rows
            // that reach it some other way (a future admin UI, a bad
            // manual UPDATE), so the validation has to live in application
            // code regardless of what the schema also enforces.
            $table->string('state')->default('closed');

            // AC3 "activation evidence" / AC4 "audited reason" live on
            // `gate_activations` (append-only history), not here — these
            // two columns are a denormalised "most recent" pointer for
            // cheap reads (an admin list screen, §8.3, later work) without
            // joining the activation log every time.
            $table->string('evidence_reference')->nullable();
            $table->timestamp('effective_at')->nullable();

            $table->string('rollback_path')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_gates');
    }
};
