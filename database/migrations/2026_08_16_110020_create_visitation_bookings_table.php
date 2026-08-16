<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `visitation_bookings` — Task 1 of the
 * `2026-08-16-p4-memorial-qr-visitation` plan (Lane 1 — Visitation).
 * A requested visit, written by exactly one Action
 * (`App\Domain\Visitation\Actions\RequestVisitation`).
 *
 * ---------------------------------------------------------------------------
 * The two uniqueness guarantees this table carries
 * ---------------------------------------------------------------------------
 * `idempotency_key` is globally unique — kiro `visitation-booking` AC7
 * ("THE SYSTEM SHALL NOT create a duplicate booking from a repeated
 * submission"). The unique constraint is the database backstop; the
 * Action's pre-check returns the incumbent for the common sequential
 * duplicate, and the `visitation_bookings_idempotency_key_unique`
 * classifier translates a concurrent collision into an incumbent return
 * instead of a raw `QueryException` (the `OrderAlreadyPaid` pattern).
 *
 * `reference` is NOT unique by constraint: it is generated as
 * `'VST-'.year.'-'.Str::random(8)` by the model's saving guard, and the
 * 8-random-uppercase-alphanumeric suffix makes collisions astronomically
 * improbable, but the reference's contract is "human-readable handle
 * shown on the confirmation card" — the idempotency key, not the
 * reference, is the correctness mechanism.
 *
 * `status` defaults to `requested` (the `VisitationBookingStatus`
 * constants class is the closed list). `visitor_count` is guarded `>= 1`
 * by the model. `facility_requests` is a JSON array cast. `visit_date`
 * is a plain date — weekday enforcement happens against the policy's
 * `operating_hours` template at request time, never at the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitation_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cemetery_id')->constrained('cemeteries')->restrictOnDelete();
            $table->foreignUuid('policy_id')->constrained('cemetery_visitation_policies')->restrictOnDelete();
            $table->date('visit_date');
            $table->unsignedInteger('visitor_count');
            $table->string('contact_phone');
            $table->string('contact_email')->nullable();
            $table->text('accessibility_needs')->nullable();
            $table->json('facility_requests')->nullable();
            $table->string('status', 32)->default('requested');
            $table->string('idempotency_key');
            $table->string('reference');
            $table->timestamps();
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitation_bookings');
    }
};
