<?php

declare(strict_types=1);

use App\Domain\OrderWorkflow\OrderPartyRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `order_parties` — Task 3. The people attached to an order. Today that is
 * exactly one role, the *pemesan* (the person placing the order), which
 * `Actions\SubmitBookingDraft` creates one row for per submission.
 *
 * The columns mirror `docs/product/booking-wizard-fields.md` §Step 6 — "Data
 * Pemesan" — and nothing beyond it. Every one of them is nullable except the
 * role, and `SubmitBookingDraft` populates only `user_id` and `role`.
 *
 * That is not an oversight, and the reason is worth stating so a reader does
 * not "fix" it: `booking_drafts` has no Step 6 columns at all
 * (`2026_08_08_130000_create_booking_drafts_table.php` — user, city, cemetery,
 * package, service type, selected services, version, idempotency key, and
 * nothing else). Steps 6-9 of the wizard are owned by lane L6 and unbuilt, so
 * at submission time this codebase genuinely does not know the orderer's name,
 * phone, email, or address. Writing a placeholder into those columns would be
 * fabricated customer data on a funeral order, which is worse than a null.
 * The row exists so the linkage is real and has somewhere to land.
 *
 * ---------------------------------------------------------------------------
 * Restricted data
 * ---------------------------------------------------------------------------
 * Everything except `role` is personal data about a bereaved customer.
 * `AGENTS.md` §Observability: "Never place restricted data in logs, Pulse,
 * Horizon tags, or error trackers." No column here is ever put in an outbox
 * payload, an audit metadata blob, or an exception message — the order
 * events this lane emits carry an order id and two status strings, and
 * nothing else (`Actions\RecordOrderStatusChange`).
 *
 * Deliberately NO identity-number column. §Step 6 lists "identity number
 * only when legally/operationally required" as optional/conditional, that
 * condition is not established anywhere in this repository, and a KTP number
 * is named in the audit batch's own Negative criteria as data that must not
 * leak. A column that does not exist cannot be filled in by accident.
 * `App\Platform\DocumentVault` is where identity DOCUMENTS belong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_parties', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // `cascadeOnDelete` is deliberately NOT used, matching
            // `order_status_events.order_id`: an order is not deletable at
            // all (`Order::delete()` refuses, and the status-event FK
            // restricts), so a cascade here would only ever describe a
            // deletion path that must not exist.
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();

            // Optional link to an authenticated account. `nullOnDelete` so a
            // user deletion never destroys the order's party record — the
            // order's own history must outlive the account.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // `App\Domain\OrderWorkflow\OrderPartyRole`.
            $table->string('role', 32);

            // §Step 6 fields. All nullable — see class doc block.
            $table->string('full_name')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email')->nullable();
            $table->text('address')->nullable();
            $table->string('relationship_to_deceased', 64)->nullable();
            $table->string('preferred_contact_channel', 32)->nullable();

            $table->timestamps();

            $table->index(['order_id', 'role']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $roles = implode("', '", array_map(
                static fn (OrderPartyRole $role): string => $role->value,
                OrderPartyRole::cases(),
            ));

            DB::statement(
                'ALTER TABLE order_parties ADD CONSTRAINT order_parties_role_check '.
                "CHECK (role IN ('{$roles}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_parties');
    }
};
