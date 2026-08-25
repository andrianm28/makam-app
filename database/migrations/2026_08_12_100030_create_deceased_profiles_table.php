<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `deceased_profiles` — Task 3. The *almarhum* record for an At-Need order.
 * Columns mirror `docs/product/booking-wizard-fields.md` §Step 7 — "Data
 * Almarhum and Documents" — data half only.
 *
 * ---------------------------------------------------------------------------
 * This table has NO writer in this task, and that is deliberate
 * ---------------------------------------------------------------------------
 * Stated up front because a table with no producer normally reads as dead
 * code. `Actions\SubmitBookingDraft` does not create a row here, for the
 * same reason it fills only two columns of `order_parties`: `booking_drafts`
 * carries no Step 7 data whatsoever, and wizard steps 6-9 are owned by lane
 * L6 and unbuilt. An empty or placeholder profile on a funeral order would
 * be fabricated data about a deceased person — the single worst thing this
 * module could invent — so no row is created until there is something true
 * to put in it.
 *
 * The table and its model are created here because the plan's Task 3 file
 * list names them and because AC5's At-Need routing is where the profile
 * hangs off; the writer arrives with the lane that collects Step 7.
 *
 * ---------------------------------------------------------------------------
 * Restricted data, and what is deliberately NOT a column
 * ---------------------------------------------------------------------------
 * `AGENTS.md` §Observability: "Never place restricted data in logs, Pulse,
 * Horizon tags, or error trackers." Nothing in this table is ever put in an
 * outbox payload, an audit metadata value, or an exception message.
 *
 * No identity number, no family-card number, and no death-certificate
 * CONTENT. §Step 7 lists KTP, Kartu Keluarga and Surat Keterangan Kematian
 * as DOCUMENTS, and this repository already has the right home for them:
 * `App\Platform\DocumentVault`, which quarantines, scans, signs short-lived
 * URLs, and audits every access. Copying their contents into a plain column
 * here would defeat all four of those controls at once. §Step 7's
 * "gender/other administrative attributes only when required" is likewise
 * absent: no requirement in this repository establishes that condition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deceased_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // One profile per order. Same `restrictOnDelete` reasoning as
            // `order_parties.order_id`.
            $table->foreignUuid('order_id')->unique()->constrained('orders')->restrictOnDelete();

            // §Step 7 "Data". All nullable — a submission may reach an
            // operator with only a name, and the record must still exist.
            $table->string('full_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('date_of_death')->nullable();
            $table->string('relationship_to_orderer', 64)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deceased_profiles');
    }
};
