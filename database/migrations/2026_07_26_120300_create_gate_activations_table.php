<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `gate_activations` — design.md: "append-only: actor, evidence ref,
 * reason, from/to state." History is never rewritten (no `updated_at`
 * mutation path exists in `GateActivationRecorder`; this migration adds no
 * `updated_at` column at all, only `created_at`, to make the append-only
 * contract structurally visible rather than merely documented).
 *
 * Backs requirements.md AC3 ("record ... activation evidence") and AC4's
 * DATA SHAPE half only — "who/when/evidence/reason" columns exist and are
 * enforced not-null by `GateActivationRecorder`. AC4's other half, "require
 * recent re-authentication", is explicitly OUT of this batch's scope: the
 * re-authentication middleware this would gate on does not exist yet
 * (S3-T2/T3, human-gated). See `GateActivationRecorder`'s doc block for the
 * exact extension point.
 *
 * `actor_reference` mirrors `ActorContext::$identityReference`'s type
 * (`int|string|null`, platform-identity-and-access design.md) without this
 * module depending on that class directly — stored as a plain string column
 * so both a local `users.id` integer and a future K1/K2 string identity
 * reference serialise into it without a schema change.
 *
 * `evidence_reference` and `reason` are NOT NULL — "No gate activation
 * without recorded evidence, owner, and date" (requirements.md Negative
 * criteria). `owner` here means the `feature_gates.owner` value in force at
 * the time of activation, not a separate column — captured implicitly via
 * the FK join; recorded explicitly on `feature_gates` itself (AC3).
 *
 * Migration timestamp slot: 2026_07_26_120000–2026_07_26_129999.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_activations', function (Blueprint $table) {
            $table->id();

            $table->string('gate_id', 32);
            $table->foreign('gate_id')
                ->references('gate_id')->on('feature_gates')
                ->cascadeOnDelete();

            // See class doc block — deliberately a loosely-typed string,
            // not a `users` FK, matching ActorContext::$identityReference's
            // own reasoning for staying identity-adapter-agnostic.
            $table->string('actor_reference');

            $table->string('evidence_reference');
            $table->text('reason');

            $table->string('from_state');
            $table->string('to_state');

            $table->timestamp('occurred_at');

            // created_at only — no updated_at. This table is never updated
            // in place; see class doc block.
            $table->timestamp('created_at')->nullable();

            $table->index(['gate_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_activations');
    }
};
