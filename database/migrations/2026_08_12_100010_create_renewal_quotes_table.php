<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `renewal_quotes` — `.kiro/specs/renewal-and-grave-registry/design.md`'s
 * Data section, backing AC6 (tariff quote shown on the fee step) and AC7
 * (late fine). L8 Task 1
 * (`docs/superpowers/plans/2026-08-12-platform-renewal-completion.md`)
 * builds the schema; `Actions\QuoteRenewal` (Task 2, this same lane) is the
 * only intended writer and does not exist yet.
 *
 * Migration timestamp slot: `2026_08_12_100000`-`2026_08_12_100029`, the
 * same batch as `2026_08_12_100000_create_renewals_table.php` — see that
 * migration's doc block for why the slot is recorded.
 *
 * ---------------------------------------------------------------------------
 * Column shape and the judgement calls behind it
 * ---------------------------------------------------------------------------
 * - `id` is a UUID, matching `renewals`.
 *
 * - `renewal_id` is `restrictOnDelete`, the same reasoning as
 *   `renewals.grave_record_id`: a quote is a real record of what a family
 *   was shown and (possibly) accepted, and a renewal being deleted must not
 *   silently take that history with it.
 *
 * - `amount_minor` / `late_fine_minor` are plain `unsignedBigInteger`
 *   minor-unit columns, never `decimal` or `float` — the same column shape
 *   `journal_entries.amount_minor` and `vendor_payables.amount_minor`
 *   already use. `App\Platform\FinancialLedger\Money`'s own doc block: "No
 *   float input, property, or arithmetic is permitted on this type."
 *   `late_fine_minor` is nullable — AC7's late fine does not apply to every
 *   renewal, and a `0` would be indistinguishable from "genuinely no fine
 *   was ever calculated", which matters for `late_fine_basis` (below) to
 *   explain.
 *
 * - `currency` is `string(3)` defaulting to `IDR`, the same shape
 *   `price_versions.currency` already uses in this codebase and
 *   `config('money.currency')`'s own value. Not a Postgres `CHECK` — unlike
 *   `journal_entries.currency`, which enforces `IDR` only because ledger
 *   entries are a closed accounting record; a quote is not.
 *
 * - `tariff_source` / `tariff_effective_at` / `tariff_source_updated_at`
 *   record the TARIFF's own provenance and effective time — deliberately
 *   NOT `grave_records.source` / `source_updated_at`, which record the
 *   REGISTRY row's provenance. `App\Domain\GraveRegistry\
 *   GraveRecordSource`'s doc block already flags this naming collision risk
 *   by name; this table is the reason that warning exists.
 *
 * - `late_fine_basis` is a nullable free-text column, not a closed list —
 *   it exists to record WHY a fine was (or was not) applied in human terms
 *   (`Actions\QuoteRenewal`, Task 2, decides the actual wording), and
 *   `NULL` distinguishes "no fine, nothing to explain" from "fine was zero
 *   for a reason".
 *
 * - `accepted_at` / `expires_at` are both nullable timestamps. A quote that
 *   has never been shown to a family has neither; `expires_at` may also be
 *   `null` for a quote type with no expiry policy — `RenewalQuote::
 *   isAcceptedAndUnexpired()` treats a `null` `expires_at` as "does not
 *   expire", never as "already expired".
 *
 * No AC11-style unique index here — a renewal legitimately accumulates more
 * than one quote (a re-quote after the tariff changes), and deciding which
 * one is authoritative at read time is `QuoteRenewal`'s job, not a database
 * constraint's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('renewal_id')->constrained('renewals')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('IDR');
            $table->string('tariff_source');
            $table->timestamp('tariff_effective_at');
            $table->timestamp('tariff_source_updated_at')->nullable();
            $table->unsignedBigInteger('late_fine_minor')->nullable();
            $table->string('late_fine_basis')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_quotes');
    }
};
