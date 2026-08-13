<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\PurgeStaleBookingDrafts;
use App\Domain\Booking\Models\BookingDraft;
use App\Platform\Audit\Models\AuditEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 */
final class PurgeStaleBookingDraftsTest extends TestCase
{
    use RefreshDatabase;

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
