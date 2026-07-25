<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `gate_environment_state` — design.md's "per-environment resolution" table,
 * backing requirements.md AC11: "scope gate state by environment ... a
 * development activation [must never] imply staging or production
 * activation."
 *
 * A row here, when present for (`gate_id`, `environment`), overrides
 * `feature_gates.state` for that environment only. Absence of a row is NOT
 * an error and does NOT imply open — `FeatureGateResolver` falls back to
 * the gate's global `feature_gates.state` when no environment-specific
 * override exists, and falls back to closed if neither resolves cleanly
 * (deny-by-default, AC10, wins over environment scoping either way).
 *
 * `environment` is a free-text string, not a fixed enum column, because
 * this repo names its own runtime lifecycle as "combined dev+staging on one
 * host, managed Postgres in production" (docs/operations/dev-staging-
 * environment.md, ADR-0027) plus a `testing` value the PHPUnit suite runs
 * under (phpunit.xml: `APP_ENV=testing`) — a hardcoded enum would need to
 * enumerate all of those and would break the moment that list changes.
 * `FeatureGateResolver` reads the live value from `config('app.env')`.
 *
 * Full environment-scoped *activation semantics* (AC11's "never imply"
 * guarantee end-to-end, e.g. write-side rules preventing a dev activation
 * from being copy-pasted into a production row) are NOT built by this
 * batch — this batch's owned task lines are AC1, AC2, AC5, AC7, AC10 only.
 * This migration exists because design.md names the table as one of this
 * module's four core data shapes, and `FeatureGateResolver` reads it as
 * part of AC10's resolution flow either way; the read path here is real,
 * the write-side governance of AC11 is a follow-up.
 *
 * Migration timestamp slot: 2026_07_26_120000–2026_07_26_129999.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_environment_state', function (Blueprint $table) {
            $table->id();

            $table->string('gate_id', 32);
            $table->foreign('gate_id')
                ->references('gate_id')->on('feature_gates')
                ->cascadeOnDelete();

            $table->string('environment', 32);

            // Same two-value contract as feature_gates.state — see that
            // migration's doc block.
            $table->string('state');

            $table->timestamps();

            $table->unique(['gate_id', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_environment_state');
    }
};
