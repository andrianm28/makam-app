<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `booking_drafts` — `.kiro/specs/booking-and-order-orchestration/design.md`
 * Data section names `booking_drafts` as one of this module's tables.
 * Backs requirements.md AC2 (resumable, idempotent, versioned, owned by
 * exactly one customer/token) for Steps 1-5 only — see this plan's Global
 * Constraints for what is deliberately deferred (Quote, Order, product-type
 * routing).
 *
 * `id` is a UUID (`HasUuids` on the model) and doubles as
 * `booking-wizard-fields.md`'s "secure opaque token" for anonymous resume —
 * `docs/contracts/openapi.yaml`'s `DraftId` parameter already commits
 * `format: uuid` for this exact resource, so this migration follows that
 * existing external contract rather than inventing a second identifier.
 *
 * `cemetery_id` is `nullOnDelete`, not `restrictOnDelete` like
 * `grave_records.cemetery_id` — an abandoned draft referencing a since-
 * deleted cemetery is not a registry-integrity concern the way a burial
 * record is; the draft simply loses its cemetery selection and the wizard
 * re-prompts step 2.
 *
 * `selected_services` stores a `list<array{code: string, quantity: int}>` —
 * a JSON column, not a relational table, because this batch does not build
 * quote issuance (AC8, out of scope): the selection is draft-local intent,
 * never snapshotted into an immutable order line. A later batch building
 * real quote issuance is expected to read this column when it exists, not
 * extend it.
 *
 * `user_id` is nullable — an anonymous draft has none until it is
 * attached to an account after login/verification
 * (`booking-wizard-fields.md` §Global behavior).
 *
 * `version` and `last_idempotency_key` implement AC2's "resumable,
 * idempotent, versioned" save contract: every step-save bumps `version`
 * and records the idempotency key it was saved under, so a replayed
 * request with the same key is a no-op rather than a double-apply. See
 * `App\Domain\Booking\Actions\SaveBookingDraftStep` (a later task in
 * this plan) for where that check actually lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedTinyInteger('current_step')->default(1);
            $table->json('completed_steps')->default(new Expression("'[]'"));

            // `city_code` stores a `LaunchCityCode::KNOWN_CODES` value. Named for
            // brevity to match this table's `cemetery_id`/`service_type` pattern,
            // not `docs/product/booking-wizard-fields.md`'s `city_or_regency_code`
            // field name verbatim — the closed-list VALUES are reused from
            // `LaunchCityCode` unchanged; only this column's own name differs.
            $table->string('city_code', 16)->nullable();

            $table->foreignUuid('cemetery_id')->nullable()->constrained('cemeteries')->nullOnDelete();
            $table->foreignId('cemetery_package_id')->nullable()->constrained('cemetery_packages')->nullOnDelete();

            $table->string('service_type', 32)->nullable();

            $table->json('selected_services')->default(new Expression("'[]'"));

            $table->unsignedInteger('version')->default(1);
            $table->string('last_idempotency_key', 64)->nullable();

            $table->timestamps();

            $table->index('user_id', 'booking_drafts_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_drafts');
    }
};
