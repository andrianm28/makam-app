<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `pre_need_consultation_requests` — Task 5
 * (`docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`,
 * Lane 3). A consultation request is the entry point the public /preneed
 * page offers while `G-LEGAL-01` keeps Pre-Need in `InterestOnly` mode:
 * the interest REGISTRATION records *that* the person is interested, the
 * consultation request records *what they want to talk about*. The two are
 * the documented §6.9 fallback surface ("registers interest; no payment
 * created") and the plan's pinned `RequestPreNeedConsultation` writer.
 *
 * ---------------------------------------------------------------------------
 * Gate-independence, and why the table carries nothing financial
 * ---------------------------------------------------------------------------
 * Like `pre_need_interests`, this table has no amount, currency, invoice,
 * or payment column — the consultation creates no financial obligation of
 * any kind, so `G-LEGAL-01` (the paid Pre-Need purchase gate) is never
 * consulted by the flow that writes it. The same fail-closed reasoning the
 * `pre_need_interests` migration documents applies here: a missing decision
 * about what a consultation could ever charge for closes the gate; the
 * table simply has nowhere to record money.
 *
 * `pre_need_interest_id` is a nullable self-service linkage: a visitor may
 * file a consultation without first registering interest, or link the
 * request to the interest row the same visit just created. `nullOnDelete`
 * mirrors `pre_need_interests.booking_draft_id` — an operator closing a
 * stale interest must not cascade-delete the person's consultation history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_need_consultation_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name', 255);
            $table->string('contact', 255);
            $table->text('message');

            $table->foreignUuid('pre_need_interest_id')
                ->nullable()
                ->constrained('pre_need_interests')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_need_consultation_requests');
    }
};
