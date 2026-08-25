<?php

declare(strict_types=1);

use App\Domain\PreNeed\PreNeedInstallmentState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pre_need_payment_schedules` — Task 3 of
 * `docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md` (Lane 2).
 * One row per installment of a pre-need case's payment schedule, created
 * by `App\Domain\PreNeed\Actions\SchedulePreNeedPayments` (AC6: "make
 * payment schedule and delinquency behavior explicit and idempotent").
 *
 * The (case, installment_number) pair is UNIQUE — the database backstop of
 * the schedule idempotency: a re-run returns the incumbent and never
 * inserts a second installment row for the same slot.
 *
 * `payment_session_id` stays null on this lane: each installment's
 * payment-link opening is a separate, later step (Task 4's admin surface
 * opens per-installment sessions via `OpenPaymentSession` on the pre-need
 * order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_need_payment_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // `restrictOnDelete()`, NOT `cascadeOnDelete()` — the same
            // evidence-preservation choice as `plot_reservations` and
            // `order_status_events`: the case keeps its full history and a
            // cascade would let a case deletion silently erase the
            // installment evidence.
            $table->foreignUuid('pre_need_case_id')
                ->constrained('pre_need_cases')
                ->restrictOnDelete();

            $table->unsignedInteger('installment_number');

            // Integer minor units — the same money shape `quotes.total_minor`
            // uses: never a float, never a decimal string.
            $table->bigInteger('amount_minor');

            // The bound quote's single currency (quotes.currency is 3 chars).
            $table->string('currency', 3);

            $table->date('due_date');

            // `App\Domain\PreNeed\PreNeedInstallmentState`: pending/paid/overdue.
            $table->string('state', 32);

            // Set by the later per-installment payment-link step (Task 4).
            $table->foreignUuid('payment_session_id')
                ->nullable()
                ->constrained('payment_sessions')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['pre_need_case_id', 'installment_number']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $states = implode("', '", array_map(
                static fn (PreNeedInstallmentState $state): string => $state->value,
                PreNeedInstallmentState::cases(),
            ));

            DB::statement(
                'ALTER TABLE pre_need_payment_schedules ADD CONSTRAINT '.
                'pre_need_payment_schedules_state_check '.
                "CHECK (state IN ('{$states}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_need_payment_schedules');
    }
};
