<?php

declare(strict_types=1);

use App\Domain\PreNeed\PreNeedInterestStatus;
use App\Platform\FeatureGate\Modes\PreNeedMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pre_need_interests` — Task 3 (AC5, Pre-Need arm). What a
 * `PRE_NEED_PLOT_PURCHASE` order routes to while `G-LEGAL-01` is closed:
 * design-system §6.9 and `App\Platform\FeatureGate\Modes\PreNeedMode`'s
 * `InterestOnly` case — "registers interest; no payment created".
 *
 * As with `funeral_cases`, the plan's Task 3 file list names the model but
 * no migration; see that migration's doc block for why the table is created
 * here anyway and reported as a plan gap.
 *
 * ---------------------------------------------------------------------------
 * The column name mismatch on `orders.pre_need_case_id`, stated plainly
 * ---------------------------------------------------------------------------
 * `2026_08_12_100000_create_orders_table.php` reserved a column called
 * `pre_need_case_id`, anticipating a `pre_need_cases` table. This table is
 * called `pre_need_interests` and its model is `PreNeedInterest`, because
 * that is what the plan's Task 3 routing rule actually specifies and,
 * substantively, because a closed `G-LEGAL-01` yields no Pre-Need *case* at
 * all — there is nothing to coordinate, only an interest to follow up. The
 * order's existing `pre_need_case_id` column holds this table's id. The
 * name is left as Task 2 wrote it rather than renamed, because renaming a
 * column belonging to another task's migration is a larger and riskier
 * change than documenting the mismatch in the three places that matter
 * (here, `Models\PreNeedInterest`, and `Actions\SubmitBookingDraft`).
 *
 * No foreign key on `orders.pre_need_case_id`, for exactly the reasons the
 * `funeral_cases` migration sets out — read that doc block, not a copy of it
 * here.
 *
 * ---------------------------------------------------------------------------
 * Deliberately absent: anything financial
 * ---------------------------------------------------------------------------
 * No amount, no currency, no invoice reference, no payment reference, no due
 * date. `G-LEGAL-01` closed means no payment object, no invoice, and no
 * financial obligation of any kind, and the cheapest way to keep that true
 * is for this table to have nowhere to put one. `docs/domain/financial-model.md`
 * §4: "A missing decision closes the relevant payment/settlement gate; it
 * does not authorize a guessed implementation."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_need_interests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // `App\Domain\PreNeed\PreNeedInterestStatus` — a third
            // vocabulary, never `OrderStatus` and never `FuneralCaseStatus`.
            $table->string('status', 64);

            /**
             * The SERVER-SIDE resolved `PreNeedMode` at the moment interest
             * was registered — `App\Platform\FeatureGate\ModeResolver::
             * preNeedMode()`, which is the one place that pairs
             * `G-LEGAL-01` with this mode.
             *
             * Recorded rather than re-derived on read: a gate can be opened
             * later, and "what was this row created under?" must stay
             * answerable afterwards. It also gives the gate read TEETH — a
             * hardcoded `interest_only` is caught by
             * `SubmitBookingDraftTest::
             * test_the_pre_need_gate_is_read_server_side_and_still_creates_no_financial_obligation_when_open`.
             */
            $table->string('gate_mode', 64);

            $table->string('service_area', 64)->nullable();

            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignUuid('booking_draft_id')
                ->nullable()
                ->constrained('booking_drafts')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('status');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", array_map(
                static fn (PreNeedInterestStatus $status): string => $status->value,
                PreNeedInterestStatus::cases(),
            ));

            $modes = implode("', '", array_map(
                static fn (PreNeedMode $mode): string => $mode->value,
                PreNeedMode::cases(),
            ));

            DB::statement(
                'ALTER TABLE pre_need_interests ADD CONSTRAINT pre_need_interests_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );

            DB::statement(
                'ALTER TABLE pre_need_interests ADD CONSTRAINT pre_need_interests_gate_mode_check '.
                "CHECK (gate_mode IN ('{$modes}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_need_interests');
    }
};
