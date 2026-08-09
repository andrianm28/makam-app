<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Platform\Outbox\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BookingDraftOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_draft_writes_one_outbox_event_with_the_agreed_shape(): void
    {
        $draft = (new StartBookingDraft)(userId: null);

        $events = OutboxEvent::query()->where('event_name', 'booking.draft_started.v1')->get();

        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertSame(1, $event->event_version);
        $this->assertSame('booking_draft', $event->aggregate_type);
        $this->assertSame((string) $draft->id, $event->aggregate_id);
        $this->assertSame("booking_draft:{$draft->id}:started", $event->idempotency_key);
        $this->assertSame('INTERNAL', $event->classification);
        $this->assertSame($draft->id, $event->payload['draft_id']);
        $this->assertSame('guest', $event->payload['actor_role']);
        $this->assertArrayHasKey('started_at', $event->payload);
        $this->assertArrayNotHasKey('user_id', $event->payload);
    }

    public function test_an_authenticated_start_records_the_customer_role_not_the_identifier(): void
    {
        (new StartBookingDraft)(userId: 4242);

        $event = OutboxEvent::query()->where('event_name', 'booking.draft_started.v1')->sole();

        $this->assertSame('customer', $event->payload['actor_role']);
        // The identifier itself must never reach the payload — only the role.
        $this->assertArrayNotHasKey('user_id', $event->payload);
        $this->assertNotContains(4242, $event->payload, 'The user identifier must not appear under any key.');
    }
}
