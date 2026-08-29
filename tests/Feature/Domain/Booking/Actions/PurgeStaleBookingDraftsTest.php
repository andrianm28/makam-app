<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\PurgeStaleBookingDrafts;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Platform\Audit\Models\AuditEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The deletion half of a draft's lifecycle — the retention ruling's
 * mechanism. A draft accumulates customer and deceased PII from Step 6
 * onward, and an abandoned draft must not leave that PII in `booking_drafts`
 * indefinitely. These tests pin:
 *
 *   1. Staleness is measured on `updated_at`, never `created_at` — a draft
 *      someone is still slowly working through has recent activity and must
 *      survive, however long ago it was first opened.
 *   2. Dry-run reports the count and changes nothing, so the window can be
 *      checked against real data before a first live run.
 *   3. The audit trail records the sweep, and the COUNT — never the PII
 *      content. A deletion is accountable, but the audit trail must not
 *      become the very copy of the personal data the purge exists to remove.
 *   4. A draft that once held a plot is still purgeable. Phase E gave
 *      `plot_reservations` a `booking_draft_id` FK, and `plot_reservations`
 *      is append-only — its rows are never deleted. Under the `RESTRICT`
 *      that FK originally shipped with, the first such draft would have
 *      raised an FK violation and, because the purge is one bulk `DELETE`
 *      in one transaction, aborted the ENTIRE nightly sweep from then on.
 *      These are the regression tests for that (whole-branch review C1).
 */
final class PurgeStaleBookingDraftsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Freezes "now" for the whole test: `makeDraftAged()` computes an aged
     * timestamp from `Carbon::now()`, and `PurgeStaleBookingDrafts` computes
     * its cutoff from a SEPARATE `Carbon::now()` call inside the action.
     * Without freezing, any real wall-clock time elapsing between those two
     * calls (a slow CI runner, an unlucky second-boundary crossing) shifts
     * the cutoff later than the aged timestamp, which silently turns
     * `test_a_draft_at_the_cutoff_boundary_survives` into `updated_at <
     * cutoff` = true — the exact-boundary draft gets deleted even though the
     * test's own intent (and the action's `<` comparison) says it should
     * survive. Confirmed as a real, reproducible CI failure (20 Aug 2026,
     * unrelated PR's rebase surfaced it), not a hypothetical race.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::now());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_drafts_untouched_past_the_window_are_deleted(): void
    {
        $abandoned = $this->makeDraftAged(days: 45);
        $fresh = BookingDraft::create();

        $deleted = (new PurgeStaleBookingDrafts)(retentionDays: 30);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('booking_drafts', ['id' => $abandoned->id]);
        $this->assertDatabaseHas('booking_drafts', ['id' => $fresh->id]);
    }

    public function test_a_draft_inside_the_window_survives_even_if_old_in_created_at(): void
    {
        $stillWorkedOn = $this->makeDraftAged(days: 60, agedBy: 'created_at');

        $deleted = (new PurgeStaleBookingDrafts)(retentionDays: 30);

        $this->assertSame(0, $deleted);
        $this->assertDatabaseHas('booking_drafts', ['id' => $stillWorkedOn->id]);
    }

    public function test_a_draft_at_the_cutoff_boundary_survives(): void
    {
        // "Older than" the window, not "as old as": a draft updated exactly
        // 30 days ago has not yet exceeded the retention window.
        $boundary = $this->makeDraftAged(days: 30);

        $deleted = (new PurgeStaleBookingDrafts)(retentionDays: 30);

        $this->assertSame(0, $deleted);
        $this->assertDatabaseHas('booking_drafts', ['id' => $boundary->id]);
    }

    public function test_dry_run_counts_without_deleting(): void
    {
        $abandoned = $this->makeDraftAged(days: 45);
        $fresh = BookingDraft::create();

        $count = (new PurgeStaleBookingDrafts)(retentionDays: 30, dryRun: true);

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('booking_drafts', ['id' => $abandoned->id]);
        $this->assertDatabaseHas('booking_drafts', ['id' => $fresh->id]);
    }

    public function test_an_empty_sweep_records_no_audit_event(): void
    {
        BookingDraft::create();

        (new PurgeStaleBookingDrafts)(retentionDays: 30);

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_a_real_sweep_records_the_count_not_the_content(): void
    {
        $this->makeDraftAged(days: 45, payload: [
            'customer_full_name' => 'Budi Santoso',
            'customer_mobile' => '081234567890',
            'customer_email' => 'budi@example.test',
        ]);
        $this->makeDraftAged(days: 45);

        (new PurgeStaleBookingDrafts)(retentionDays: 30);

        $event = AuditEvent::query()->where('action', 'BOOKING_DRAFTS_PURGED')->firstOrFail();

        // The subject is a sweep (a date window), never a draft row — the
        // count and the window are the identifying facts worth keeping, and
        // neither carries personal data.
        $this->assertSame('booking_draft_sweep', $event->subject_type);
        $this->assertSame(2, (int) $event->subject_version);
        $this->assertSame('system', $event->actor_role);
        $this->assertNull($event->actor_ref);

        // No customer PII may ride along in the audit trail.
        foreach (['Budi Santoso', '081234567890', 'budi@example.test'] as $pii) {
            $this->assertStringNotContainsString($pii, (string) json_encode($event->metadata));
        }
    }

    public function test_purged_drafts_are_gone_irrespective_of_their_pii(): void
    {
        $withPii = $this->makeDraftAged(days: 45, payload: [
            'customer_full_name' => 'Siti Rahayu',
        ]);

        (new PurgeStaleBookingDrafts)(retentionDays: 30);

        $this->assertDatabaseMissing('booking_drafts', ['id' => $withPii->id]);
    }

    public function test_a_stale_draft_with_a_live_plot_hold_is_still_purged(): void
    {
        $plot = $this->makePlot();
        $abandoned = $this->makeDraftAged(days: 45, payload: [
            'customer_full_name' => 'Rina Kartika',
        ]);

        $hold = (new HoldPlotForDraft)($plot, $abandoned, "booking_draft:{$abandoned->getKey()}");

        $deleted = (new PurgeStaleBookingDrafts)(retentionDays: 30);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('booking_drafts', ['id' => $abandoned->id]);

        // The reservation evidence survives the purge — only the link to the
        // erased draft is severed (`nullOnDelete`).
        $survivor = PlotReservation::query()->findOrFail($hold->getKey());
        $this->assertNull($survivor->booking_draft_id);
        $this->assertSame(PlotReservationState::HELD, $survivor->state);
        $this->assertSame($plot->getKey(), $survivor->plot_id);

        // `reserved_by_ref` keeps textual traceability to the purged draft.
        $this->assertSame("booking_draft:{$abandoned->id}", $survivor->reserved_by_ref);
    }

    public function test_a_stale_draft_whose_hold_already_reached_a_terminal_state_is_still_purged(): void
    {
        $plot = $this->makePlot();
        $abandoned = $this->makeDraftAged(days: 45);

        $hold = (new HoldPlotForDraft)($plot, $abandoned, "booking_draft:{$abandoned->getKey()}", ttlMinutes: -5);
        (new ExpirePlotReservation)($hold, 'system', 'system');

        // A terminal chain is TWO rows carrying `booking_draft_id`, not one
        // — `ExpirePlotReservation` appends rather than mutating.
        $this->assertSame(2, PlotReservation::query()->where('booking_draft_id', $abandoned->id)->count());

        $deleted = (new PurgeStaleBookingDrafts)(retentionDays: 30);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('booking_drafts', ['id' => $abandoned->id]);
        $this->assertSame(2, PlotReservation::query()->whereNull('booking_draft_id')->count());
    }

    public function test_one_draft_with_a_hold_cannot_block_the_rest_of_the_sweep(): void
    {
        // The real shape of the C1 defect: the purge is a single bulk
        // DELETE in one transaction, so under RESTRICT the offending row
        // took every OTHER stale draft's PII down with it.
        $withHold = $this->makeDraftAged(days: 45);
        (new HoldPlotForDraft)($this->makePlot(), $withHold, "booking_draft:{$withHold->getKey()}");

        $plain = $this->makeDraftAged(days: 45, payload: ['customer_full_name' => 'Agus Wibowo']);

        $deleted = (new PurgeStaleBookingDrafts)(retentionDays: 30);

        $this->assertSame(2, $deleted);
        $this->assertDatabaseMissing('booking_drafts', ['id' => $withHold->id]);
        $this->assertDatabaseMissing('booking_drafts', ['id' => $plain->id]);
    }

    private function makePlot(): GravePlot
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 1,
        ]);

        return GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => PlotState::AVAILABLE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeDraftAged(int $days, string $agedBy = 'updated_at', array $payload = []): BookingDraft
    {
        $draft = BookingDraft::create($payload);

        BookingDraft::query()->whereKey($draft->id)->update([
            $agedBy => Carbon::now()->subDays($days)->toDateTimeString(),
        ]);

        return $draft->fresh();
    }
}
