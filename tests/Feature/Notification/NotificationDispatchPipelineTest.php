<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Models\User;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Contracts\Channel;
use App\Platform\Notification\DeliveryState;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use App\Platform\Notification\Models\InAppNotification;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationEvent;
use App\Platform\Notification\Models\NotificationRecipient;
use App\Platform\Outbox\Jobs\PublishOutboxEventJob;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Notification\FakeChannel;
use Tests\TestCase;

/**
 * Task 3 of the L2 `platform-notifications` lane — the outbox-fed dispatch
 * path (`Actions\DispatchNotification`, `Jobs\ConsumeOutboxNotificationJob`,
 * `Jobs\SendNotificationChannelJob`) and its delivery-state recording.
 *
 * Every scenario here records a REAL outbox row for `booking.draft_submitted
 * .v2` directly via `Outbox::record()`, never through a real producer —
 * task-3-brief.md D3 documents plainly that no producer for any of the 6
 * outbox-mapped matrix events exists in this codebase yet
 * (`BookingWizardStep::LAST_IMPLEMENTED` is 5; Step 9 does not exist). This
 * proves the dispatch pipeline itself, not end-to-end production coverage.
 *
 * Uses `docs/contracts/notification-matrix.md`'s real "Booking submitted"
 * row: `Customer: EMAIL/WA`, `Pengelola TPU/TPS: IN_APP/EMAIL for selected
 * location`, `Admin platform: IN_APP` (not reachable here — nothing links a
 * `booking_draft` to a `business_entity` scope, the same gap
 * `RecipientResolverTest` documents), `Vendor: none`.
 */
final class NotificationDispatchPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_ac8_a_duplicate_outbox_delivery_produces_exactly_one_of_everything(): void
    {
        $this->bindChannel(new FakeChannel);
        $this->bindWhatsAppMode(open: true);

        [$draft, $operatorRef] = $this->bookingSubmittedFixture();
        $outboxEventId = $this->recordBookingSubmitted($draft->id);

        ConsumeOutboxNotificationJob::dispatchSync($outboxEventId);
        ConsumeOutboxNotificationJob::dispatchSync($outboxEventId);

        $this->assertSame(1, NotificationEvent::query()->where('event_id', $outboxEventId)->count());
        $this->assertSame(
            1,
            NotificationRecipient::query()->where('event_id', $outboxEventId)->where('recipient_ref', (string) $draft->user_id)->count()
        );
        $this->assertSame(
            1,
            NotificationRecipient::query()->where('event_id', $outboxEventId)->where('recipient_ref', $operatorRef)->count()
        );

        // Customer: EMAIL + WA (matrix cell "EMAIL/WA"). Operator: EMAIL
        // only (matrix cell "IN_APP/EMAIL for selected location" — no WA
        // token).
        $this->assertSame(1, $this->deliveryCount($outboxEventId, (string) $draft->user_id, 'EMAIL'));
        $this->assertSame(1, $this->deliveryCount($outboxEventId, (string) $draft->user_id, 'WA'));
        $this->assertSame(1, $this->deliveryCount($outboxEventId, $operatorRef, 'EMAIL'));
        $this->assertSame(1, InAppNotification::query()->where('event_id', $outboxEventId)->where('recipient_ref', $operatorRef)->count());
    }

    public function test_ac7_the_in_app_record_survives_a_throwing_channel(): void
    {
        $this->bindChannel(new FakeChannel(throws: true));
        $this->bindWhatsAppMode(open: true);

        [$draft, $operatorRef] = $this->bookingSubmittedFixture();
        $outboxEventId = $this->recordBookingSubmitted($draft->id);

        // Full chain: publish -> listener -> consume job -> commit -> per-
        // channel job, all synchronous under QUEUE_CONNECTION=sync.
        (new PublishOutboxEventJob($outboxEventId))->handle();

        $this->assertTrue(
            InAppNotification::query()->where('event_id', $outboxEventId)->where('recipient_ref', $operatorRef)->exists(),
            'AC7: the in-app record must exist even though the channel always throws.'
        );

        $delivery = NotificationDelivery::query()
            ->where('event_id', $outboxEventId)
            ->where('recipient_ref', $operatorRef)
            ->where('channel', 'EMAIL')
            ->sole();

        $this->assertSame(DeliveryState::Failed, $delivery->state);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertNotNull($delivery->failure_message);
    }

    public function test_ac5_a_throwing_channel_never_changes_business_state_or_propagates(): void
    {
        $this->bindChannel(new FakeChannel(throws: true));
        $this->bindWhatsAppMode(open: true);

        [$draft] = $this->bookingSubmittedFixture();
        $draftBefore = $draft->fresh()->toArray();

        $outboxEventId = $this->recordBookingSubmitted($draft->id);
        $outboxRowBefore = OutboxEvent::query()->findOrFail($outboxEventId)->toArray();

        // No exception must escape this call.
        (new PublishOutboxEventJob($outboxEventId))->handle();

        $this->assertSame($draftBefore, $draft->fresh()->toArray(), 'The booking_drafts row must be untouched by a channel failure.');
        $this->assertSame($outboxRowBefore, OutboxEvent::query()->findOrFail($outboxEventId)->toArray(), 'The outbox_events row must be untouched by a channel failure.');
        // 2, not 1: StartBookingDraft (bookingSubmittedFixture()) already
        // wrote its own booking.draft_started.v1 outbox row as a real side
        // effect — both it and the booking.draft_submitted.v2 row this test
        // adds must survive untouched.
        $this->assertSame(2, OutboxEvent::query()->count());
        $this->assertSame(1, BookingDraft::query()->count());
    }

    public function test_ac2_ac12_whatsapp_gate_closed_records_unavailable_not_a_silent_drop(): void
    {
        $sent = new FakeChannel;
        $this->bindChannel($sent);
        $this->bindWhatsAppMode(open: false);

        [$draft] = $this->bookingSubmittedFixture();
        $outboxEventId = $this->recordBookingSubmitted($draft->id);

        (new PublishOutboxEventJob($outboxEventId))->handle();

        $waDelivery = NotificationDelivery::query()
            ->where('event_id', $outboxEventId)
            ->where('recipient_ref', (string) $draft->user_id)
            ->where('channel', 'WA')
            ->sole();

        $this->assertSame(DeliveryState::Unavailable, $waDelivery->state);
        $this->assertSame(
            0,
            NotificationDelivery::query()->where('event_id', $outboxEventId)->where('channel', 'WA')->where('state', DeliveryState::Sent)->count()
        );
        $this->assertSame(
            0,
            NotificationDelivery::query()->where('event_id', $outboxEventId)->where('channel', 'WA')->where('state', DeliveryState::Queued)->count()
        );

        // No channel job was ever dispatched for the UNAVAILABLE row — the
        // FakeChannel is only ever called for EMAIL deliveries (the
        // customer's and the operator's), never for the dropped WA one.
        $this->assertCount(2, $sent->sent, 'Only the two EMAIL deliveries should have reached the channel.');
        foreach ($sent->sent as $deliverySeen) {
            $this->assertSame('EMAIL', $deliverySeen->channel);
        }
    }

    public function test_ac4_no_delivery_row_means_no_delivery_claim(): void
    {
        $this->bindChannel(new FakeChannel);
        $this->bindWhatsAppMode(open: true);

        // A mapped event whose aggregate type the subject seam cannot
        // resolve (task-3-brief.md D3 / D4's own AC4 requirement): the
        // event is still recorded, with zero recipients and therefore zero
        // deliveries.
        $outboxEventId = Outbox::record(
            eventName: 'booking.draft_submitted.v2',
            eventVersion: 2,
            aggregateType: 'grave_marker',
            aggregateId: 'gm-1',
            data: [],
            classification: OutboxClassification::Internal,
        )->getKey();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEventId);

        $this->assertTrue(NotificationEvent::query()->where('event_id', $outboxEventId)->exists());
        $this->assertFalse(NotificationDelivery::query()->where('event_id', $outboxEventId)->exists());
        $this->assertFalse(NotificationRecipient::query()->where('event_id', $outboxEventId)->exists());

        // notification_deliveries is the only table with a "state" concept
        // — notification_events carries no delivered/sent claim of its own.
        $this->assertEqualsCanonicalizing(
            ['event_id', 'event_name', 'matrix_event_name', 'aggregate_type', 'aggregate_id', 'trace_id', 'consumed_at'],
            Schema::getColumnListing('notification_events'),
        );
    }

    public function test_an_unmapped_outbox_event_name_produces_zero_notification_rows(): void
    {
        $this->bindChannel(new FakeChannel);
        $this->bindWhatsAppMode(open: true);

        $outboxEventId = Outbox::record(
            eventName: 'totally.unmapped.v1',
            eventVersion: 1,
            aggregateType: 'booking_draft',
            aggregateId: 'whatever',
            data: [],
            classification: OutboxClassification::Internal,
        )->getKey();

        // Through the real listener path — D1's classification lookup must
        // filter this out before ConsumeOutboxNotificationJob is even
        // dispatched.
        (new PublishOutboxEventJob($outboxEventId))->handle();

        $this->assertFalse(NotificationEvent::query()->where('event_id', $outboxEventId)->exists());
        $this->assertSame(0, NotificationDelivery::query()->count());
        $this->assertSame(0, NotificationRecipient::query()->count());
        $this->assertSame(0, InAppNotification::query()->count());
    }

    public function test_cross_scope_leakage_an_actor_scoped_to_a_different_cemetery_receives_nothing(): void
    {
        $this->bindChannel(new FakeChannel);
        $this->bindWhatsAppMode(open: true);

        [$draft] = $this->bookingSubmittedFixture();

        $otherCemetery = $this->createCemetery();
        ScopeAssignment::query()->create([
            'actor_identifier' => 'other-cemetery-operator',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $otherCemetery->id,
        ]);

        $outboxEventId = $this->recordBookingSubmitted($draft->id);
        ConsumeOutboxNotificationJob::dispatchSync($outboxEventId);

        $this->assertFalse(
            NotificationRecipient::query()->where('event_id', $outboxEventId)->where('recipient_ref', 'other-cemetery-operator')->exists()
        );
    }

    private function deliveryCount(string $eventId, string $recipientRef, string $channel): int
    {
        return NotificationDelivery::query()
            ->where('event_id', $eventId)
            ->where('recipient_ref', $recipientRef)
            ->where('channel', $channel)
            ->count();
    }

    /**
     * @return array{0: BookingDraft, 1: string}
     */
    private function bookingSubmittedFixture(): array
    {
        $user = User::factory()->create();
        $cemetery = $this->createCemetery();

        $draft = (new StartBookingDraft)(userId: $user->id);
        $draft->forceFill(['cemetery_id' => $cemetery->id])->save();

        ScopeAssignment::query()->create([
            'actor_identifier' => 'operator-1',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->id,
        ]);

        return [$draft->fresh(), 'operator-1'];
    }

    private function recordBookingSubmitted(string $draftId): string
    {
        return Outbox::record(
            eventName: 'booking.draft_submitted.v2',
            eventVersion: 2,
            aggregateType: 'booking_draft',
            aggregateId: $draftId,
            data: ['draft_id' => $draftId],
            classification: OutboxClassification::Internal,
        )->getKey();
    }

    private function createCemetery(): Cemetery
    {
        static $sequence = 0;
        $sequence++;

        return Cemetery::create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => "Notification Dispatch Test Cemetery {$sequence}",
            'slug' => "notification-dispatch-test-cemetery-{$sequence}",
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Uji Coba Notifikasi',
        ]);
    }

    private function bindChannel(Channel $channel): void
    {
        $this->app->instance(Channel::class, $channel);
    }

    private function bindWhatsAppMode(bool $open): void
    {
        $source = new class($open) implements GateRegistrySource
        {
            public function __construct(private readonly bool $open) {}

            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot(['G-WA-01' => GateState::fromRecord('G-WA-01', open: $this->open)]);
            }
        };

        $this->app->instance(ModeResolver::class, new ModeResolver(new FeatureGateResolver($source)));
    }
}
