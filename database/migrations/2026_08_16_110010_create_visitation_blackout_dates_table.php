<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `visitation_blackout_dates` — Task 1 of the
 * `2026-08-16-p4-memorial-qr-visitation` plan (Lane 1 — Visitation).
 * One-off closed dates for a visitation policy, each with a visitor-
 * visible reason (kiro `visitation-booking` AC2: blackout dates are
 * enforced; the design spec §6.2: a refusal surfaces a specific reason,
 * never a bare "tidak tersedia").
 *
 * `reason` is required — the model's saving guard rejects blank values —
 * because a blackout without a reason would be indistinguishable from a
 * data-entry error at exactly the moment a family asks why their date is
 * unavailable.
 *
 * `cascadeOnDelete` — deliberate opposite of the policies table's
 * `restrictOnDelete`: a blackout date is derived configuration scoped to
 * its policy; deleting the policy deletes its blackout list, there is no
 * external evidence trail pointing back at a blackout row.
 *
 * The unique `(policy_id, date)` is the module's "one blackout per date"
 * guarantee — the same backstop shape the capacity ledger uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitation_blackout_dates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('policy_id')->constrained('cemetery_visitation_policies')->cascadeOnDelete();
            $table->date('date');
            $table->string('reason');
            $table->timestamps();
            $table->unique(['policy_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitation_blackout_dates');
    }
};
