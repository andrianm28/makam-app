<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\Models\BookingDraft;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Outbox\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class BookingDraftOutboxTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The atomicity tests below register static `creating()` listeners to
     * simulate downstream write failures. Eloquent model event listeners are
     * process-global and are reset by neither `RefreshDatabase` nor Laravel's
     * container rebuild, so without this teardown a leaked listener would make
     * every later `OutboxEvent`/`BookingDraft`/`AuditEvent` creation throw for
     * the rest of the PHPUnit process — in this class and every other one.
     * Flushing unconditionally after every test, rather than inside the tests
     * that register listeners, stays correct even when one of them fails
     * before reaching its own assertions. Same precedent and same reasoning as
     * `tests/Feature/FeatureGate/GateActivationRecorderTest.php`.
     */
    protected function tearDown(): void
    {
        OutboxEvent::flushEventListeners();
        BookingDraft::flushEventListeners();
        AuditEvent::flushEventListeners();

        parent::tearDown();
    }

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

    public function test_a_failing_outbox_write_rolls_back_the_draft_itself(): void
    {
        // `platform-outbox` AC1: "A committed mutation with no outbox row is a
        // defect." The draft row is inserted BEFORE `Outbox::record()` runs, so
        // failing the outbox write here proves the already-inserted draft is
        // rolled back rather than merely never written.
        $before = BookingDraft::query()->count();

        OutboxEvent::creating(function (): void {
            throw new RuntimeException('simulated outbox write failure');
        });

        try {
            (new StartBookingDraft)(userId: null);
            $this->fail('Expected the simulated outbox failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated outbox write failure', $exception->getMessage());
        }

        $this->assertSame($before, BookingDraft::query()->count(), 'The draft must not survive a failed outbox write.');
        $this->assertSame(0, OutboxEvent::query()->count());
    }

    public function test_a_failing_draft_write_leaves_no_outbox_event_behind(): void
    {
        // The weaker of the two directions by construction: `BookingDraft` is
        // created first, so this proves the ORDERING (nothing downstream runs
        // once the mutation fails), not that a written outbox row rolls back.
        // The test below is what pins that direction.
        BookingDraft::creating(function (): void {
            throw new RuntimeException('simulated draft write failure');
        });

        try {
            (new StartBookingDraft)(userId: null);
            $this->fail('Expected the simulated draft failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated draft write failure', $exception->getMessage());
        }

        $this->assertSame(0, OutboxEvent::query()->count());
        $this->assertSame(0, BookingDraft::query()->count());
    }

    public function test_a_failing_audit_write_rolls_back_the_already_written_outbox_event(): void
    {
        // The inverse of AC1, and the direction that needs a post-outbox
        // failure to be provable at all: an outbox row with no committed
        // mutation is equally a defect. `StartBookingDraft` runs
        // `Audit::record()` AFTER `Outbox::record()`, so when the audit write
        // throws, the outbox row has genuinely already been inserted inside the
        // transaction — asserting it is gone proves rollback, not ordering.
        AuditEvent::creating(function (): void {
            throw new RuntimeException('simulated audit write failure');
        });

        try {
            (new StartBookingDraft)(userId: null);
            $this->fail('Expected the simulated audit failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated audit write failure', $exception->getMessage());
        }

        $this->assertSame(0, OutboxEvent::query()->count(), 'The outbox event must not outlive the transaction that wrote it.');
        $this->assertSame(0, BookingDraft::query()->count());
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_a_failing_audit_write_rolls_back_a_step_save_and_its_outbox_event(): void
    {
        // Same proof for the second producer. The listener is registered only
        // after the draft exists, so the start's own writes stand and this
        // asserts the SAVE's writes specifically — the same "the earlier
        // successful call must survive" shape `GateActivationRecorderTest`
        // uses for its collision case.
        $draft = (new StartBookingDraft)(userId: null);

        AuditEvent::creating(function (): void {
            throw new RuntimeException('simulated audit write failure');
        });

        try {
            (new SaveBookingDraftStep)($draft, 1, ['city_code' => 'JAKARTA'], 'key-1');
            $this->fail('Expected the simulated audit failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated audit write failure', $exception->getMessage());
        }

        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'booking.draft_step_saved.v1')->count());
        // The start's own event is untouched — only the rolled-back save's.
        $this->assertSame(1, OutboxEvent::query()->count());

        $persisted = BookingDraft::query()->findOrFail($draft->id);
        $this->assertSame(1, $persisted->version, 'A rolled-back save must not bump the version.');
        $this->assertSame([], $persisted->completed_steps);
        $this->assertNull($persisted->city_code);
        $this->assertNull($persisted->last_idempotency_key);
    }
}
