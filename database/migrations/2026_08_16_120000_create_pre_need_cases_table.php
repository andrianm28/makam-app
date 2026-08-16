<?php

declare(strict_types=1);

use App\Domain\PreNeed\PreNeedCaseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pre_need_cases` — Task 3 of
 * `docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md` (Lane 2).
 * The paid Pre-Need flow's coordination aggregate: one row per contracted
 * Pre-Need purchase, carrying references to the proposal (cemetery +
 * package), the optional plot reservation (P3 seam), the issued quote, the
 * accepted agreement, and — after AC8 activation — the NEW At-Need
 * `FuneralCase` opened from the contract.
 *
 * ---------------------------------------------------------------------------
 * Relationship to `pre_need_interests` and the submit-time order chain
 * ---------------------------------------------------------------------------
 * A closed `G-LEGAL-01` yields an INTEREST row and nothing else
 * (`2026_08_12_100018_create_pre_need_interests_table.php`); once the gate
 * opens, the admin surface promotes an interest into a CASE (Task 4). The
 * case links `pre_need_interest_id`, and its order is resolved through the
 * submit-time chain — interest -> `booking_draft_id` -> the order carrying
 * that `booking_draft_id` (whose `pre_need_case_id` holds the interest id,
 * not the case id — see the interests migration's doc block for the
 * mismatch) — by `App\Domain\PreNeed\Models\PreNeedCase::order()`.
 *
 * ---------------------------------------------------------------------------
 * Plan-gap fill: the acceptance and settlement evidence columns
 * ---------------------------------------------------------------------------
 * The plan's fillable list names the eight reference columns only, but the
 * Task 3 brief pins two more behaviours that need columns:
 *
 *   - AC2/AC5 bind the acceptance to the exact agreement AND quote versions.
 *     Lane 1's `Agreement` class does not exist on this branch (parallel
 *     lane), so the acceptance is recorded ON THE CASE — `agreement_id` (an
 *     opaque reference string, no FK: the `agreements` table belongs to
 *     Lane 1 and does not exist here), `accepted_by_ref`, and
 *     `accepted_quote_id`. The Lane-1 binding reconciles at the merge; the
 *     case's acceptance is the source of truth.
 *   - `SettlePreNeed` takes a `$paidSourceRef` (the verified payment
 *     evidence); `settled_paid_source_ref` records which evidence settled
 *     the case, mirroring `orders.paid_source_ref`.
 *
 * `agreement_id` is a plain string rather than a UUID FK because Lane 1's
 * `agreements.id` shape is not on this branch; the string is documented in
 * `AcceptPreNeedAgreement` as opaque.
 *
 * ---------------------------------------------------------------------------
 * Delete discipline
 * ---------------------------------------------------------------------------
 * The case keeps its full history — no deletes. The model refuses
 * `delete()` at the application layer (`Models\PreNeedCase::delete()`); the
 * schedule rows reference the case with `restrictOnDelete()` for the same
 * reason the reservation evidence is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_need_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The promoted interest. Nullable + nullOnDelete: an interest
            // row can be removed (it has no delete guard of its own), and
            // the case's history must survive — the order chain then
            // resolves null and the order-dependent actions refuse
            // honestly.
            $table->foreignUuid('pre_need_interest_id')
                ->nullable()
                ->constrained('pre_need_interests')
                ->nullOnDelete();

            // `App\Domain\PreNeed\PreNeedCaseStatus` — a fourth vocabulary,
            // never `OrderStatus` and never `FuneralCaseStatus`, for the
            // same reason `PreNeedInterestStatus` documents its own.
            $table->string('status', 64);

            // The proposal refs (ProposePreNeedPackage).
            $table->foreignUuid('cemetery_id')
                ->nullable()
                ->constrained('cemeteries')
                ->nullOnDelete();

            // `cemetery_packages.id` is BIGINT (`$table->id()` — the P2/P3
            // lesson: packages use integer ids), so this is `foreignId`,
            // NOT `foreignUuid` — PostgreSQL rejects a uuid->bigint
            // constraint with SQLSTATE[42804] ("incompatible types"), and
            // SQLite does not enforce the type so the mismatch is invisible
            // on the local suite. Same shape as `booking_drafts.
            // cemetery_package_id` (`2026_08_08_130000_create_booking_drafts_
            // table.php`).
            $table->foreignId('cemetery_package_id')
                ->nullable()
                ->constrained('cemetery_packages')
                ->nullOnDelete();

            // Opaque agreement reference — Lane 1's `agreements` table is a
            // parallel-lane artifact not present on this branch, so no FK;
            // see the class doc block.
            $table->string('agreement_id', 64)->nullable();

            $table->foreignUuid('quote_id')
                ->nullable()
                ->constrained('quotes')
                ->nullOnDelete();

            $table->foreignUuid('plot_reservation_id')
                ->nullable()
                ->constrained('plot_reservations')
                ->nullOnDelete();

            // AC8: the NEW At-Need FuneralCase opened at activation; the
            // original case's own links are untouched.
            $table->foreignUuid('activated_funeral_case_id')
                ->nullable()
                ->constrained('funeral_cases')
                ->nullOnDelete();

            // AC2/AC5 case-level acceptance binding (see class doc block).
            $table->string('accepted_by_ref', 255)->nullable();
            $table->foreignUuid('accepted_quote_id')
                ->nullable()
                ->constrained('quotes')
                ->nullOnDelete();

            // The verified payment evidence that settled the case.
            $table->string('settled_paid_source_ref', 255)->nullable();

            $table->timestamps();

            $table->index('status');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", array_map(
                static fn (PreNeedCaseStatus $status): string => $status->value,
                PreNeedCaseStatus::cases(),
            ));

            DB::statement(
                'ALTER TABLE pre_need_cases ADD CONSTRAINT pre_need_cases_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_need_cases');
    }
};
