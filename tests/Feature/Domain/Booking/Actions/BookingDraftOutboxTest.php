<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
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

    public function test_saving_a_step_writes_one_outbox_event_referencing_the_draft_not_its_content(): void
    {
        $draft = (new StartBookingDraft)(userId: null);

        (new SaveBookingDraftStep)($draft, 1, ['city_code' => 'JAKARTA'], 'key-1');

        $event = OutboxEvent::query()->where('event_name', 'booking.draft_step_saved.v1')->sole();

        $this->assertSame('booking_draft', $event->aggregate_type);
        $this->assertSame((string) $draft->id, $event->aggregate_id);
        $this->assertSame(1, $event->payload['step']);
        $this->assertSame($draft->id, $event->payload['draft_id']);
        $this->assertSame([1], $event->payload['completed_steps']);
        // AC2: reference, never content. The city belongs to the draft row.
        $this->assertArrayNotHasKey('city_code', $event->payload);
    }

    public function test_an_idempotent_replay_does_not_write_a_second_outbox_event(): void
    {
        $draft = (new StartBookingDraft)(userId: null);

        $saved = (new SaveBookingDraftStep)($draft, 1, ['city_code' => 'JAKARTA'], 'replayed-key');
        (new SaveBookingDraftStep)($saved, 1, ['city_code' => 'JAKARTA'], 'replayed-key');

        $this->assertSame(
            1,
            OutboxEvent::query()->where('event_name', 'booking.draft_step_saved.v1')->count()
        );
    }

    public function test_a_rejected_step_save_writes_no_outbox_event_at_all(): void
    {
        $draft = (new StartBookingDraft)(userId: null);

        try {
            (new SaveBookingDraftStep)($draft, 1, ['city_code' => 'NOT_A_CITY'], 'key-1');
            $this->fail('Expected the invalid city to be rejected.');
        } catch (\App\Domain\Booking\Exceptions\BookingStepValidationException) {
            // expected
        }

        $this->assertSame(
            0,
            OutboxEvent::query()->where('event_name', 'booking.draft_step_saved.v1')->count()
        );
    }
}
