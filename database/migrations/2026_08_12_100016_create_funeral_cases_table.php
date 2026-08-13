<?php

declare(strict_types=1);

use App\Domain\FuneralCase\FuneralCaseStatus;
use App\Domain\FuneralCase\FuneralCaseUrgency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `funeral_cases` — Task 3 (AC5, At-Need arm). The OPERATIONAL aggregate an
 * At-Need order routes to. `docs/domain/funeral-case-model.md` §Purpose:
 * "At-Need and Urgent are human service orchestration problems.
 * `order.status` alone cannot represent the work required."
 *
 * NOT in the plan's Task 3 file list, which names
 * `app/Domain/FuneralCase/Models/FuneralCase.php` but no migration to back
 * it. That is an omission in the plan, not a signal that the table exists:
 * `2026_08_12_100000_create_orders_table.php` states outright that "neither
 * `funeral_cases` nor `pre_need_cases` exists yet in this repository (grep
 * confirms no migration for either)". A model with no table cannot satisfy
 * AC5, so the table is created here and the gap is called out in the Task 3
 * report rather than papered over.
 *
 * ---------------------------------------------------------------------------
 * Scope: the plan's "minimum fields urgency/area/owner/deadlines", and no more
 * ---------------------------------------------------------------------------
 * `funeral-case-model.md` §Aggregate lists thirteen things a case eventually
 * holds — tasks, communications, appointments, transport milestones,
 * incidents, completion evidence, and so on. None of those belongs to AC5,
 * none has an owning task in this plan, and inventing their columns now
 * would be schema written ahead of the code that constrains it — exactly
 * what the sibling `orders` migration refused to do for `product_type`. Only
 * the four minimum fields the plan names are here.
 *
 * `case_manager_ref` (the "owner") is nullable and is NOT set at creation.
 * Assignment is its own catalogued event (`event-catalog.md`:
 * `funeral_case.manager_assigned.v1`, "Includes handover reason when
 * changed") and its own workflow, owned by neither this task nor this plan.
 * A case with no manager yet is the real state after submission; naming a
 * fabricated owner would be worse than an honest null.
 *
 * The two deadline columns are nullable and are likewise NOT set at
 * creation. `funeral-case-model.md` §Urgent readiness makes first-response
 * and confirmation targets a PER-SERVICE-AREA configuration, and
 * `App\Domain\Booking\BookingServiceType`'s doc block records that the
 * Urgent SLA is `docs/governance/assumptions-and-gates.md` §5 open decision
 * #6 and is unresolved — the same reason that class enforces no operational
 * precondition on `URGENT_TODAY`. There is no source for a deadline value,
 * so none is guessed.
 *
 * ---------------------------------------------------------------------------
 * Why `orders.funeral_case_id` still has no foreign key
 * ---------------------------------------------------------------------------
 * The `orders` migration expected "a later lane that creates those tables …
 * to add the constraint then". This lane deliberately does not, and the gap
 * is recorded rather than assumed absent:
 *
 *   - A `pgsql`-guarded `ALTER TABLE orders ADD CONSTRAINT … FOREIGN KEY`
 *     would exist in production and not in the SQLite test suite. That is
 *     tolerable for a CHECK (the sibling migrations do it) but not for
 *     referential integrity, which is the constraint most likely to be
 *     relied on by later code that was only ever run against SQLite.
 *   - The portable alternative, `Schema::table('orders', … ->foreign(…))`,
 *     compiles on SQLite to a full table REBUILD (`SQLiteGrammar`'s
 *     `$alterCommands` list). `orders` carries two hand-written
 *     `DB::statement()` indexes — `orders_idempotency_key_unq` here, and it
 *     is the parent of `order_status_events_paid_once` — and a rebuild that
 *     silently dropped a partial unique index would destroy this lane's
 *     load-bearing invariant while every test stayed green.
 *
 * The link is therefore application-enforced for now (one writer,
 * `Actions\OpenFuneralCase`, called from one place). Task 10 owns real
 * PostgreSQL 18 verification and is the right place to add the FK where a
 * rebuild is not involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funeral_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // `App\Domain\FuneralCase\FuneralCaseStatus` — NEVER
            // `OrderStatus`. Two vocabularies, two columns.
            $table->string('status', 64);

            // `App\Domain\FuneralCase\FuneralCaseUrgency`.
            $table->string('urgency', 32);

            // The service area. `App\Domain\CemeteryDirectory\LaunchCityCode`
            // today; nullable because a case may be opened from a draft that
            // never reached the city step.
            $table->string('service_area', 64)->nullable();

            // The "owner" — see class doc block. Never set at creation.
            $table->string('case_manager_ref')->nullable();

            // Deadlines — see class doc block. Never set at creation.
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('service_due_at')->nullable();

            // Provenance back to the submission this case came from. Same
            // `nullOnDelete` reasoning as `orders.booking_draft_id`: a draft
            // cleanup must never delete an open funeral case, it may only
            // cost it the convenience link.
            $table->foreignUuid('booking_draft_id')
                ->nullable()
                ->constrained('booking_drafts')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index(['service_area', 'urgency']);
        });

        // SQLite cannot ADD CONSTRAINT — same guard, and same reasoning, as
        // `orders_status_check`.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", array_map(
                static fn (FuneralCaseStatus $status): string => $status->value,
                FuneralCaseStatus::cases(),
            ));

            $urgencies = implode("', '", array_map(
                static fn (FuneralCaseUrgency $urgency): string => $urgency->value,
                FuneralCaseUrgency::cases(),
            ));

            DB::statement(
                'ALTER TABLE funeral_cases ADD CONSTRAINT funeral_cases_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );

            DB::statement(
                'ALTER TABLE funeral_cases ADD CONSTRAINT funeral_cases_urgency_check '.
                "CHECK (urgency IN ('{$urgencies}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('funeral_cases');
    }
};
