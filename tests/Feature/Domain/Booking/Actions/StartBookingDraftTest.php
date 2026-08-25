<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StartBookingDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_fresh_anonymous_draft_at_step_one(): void
    {
        $draft = (new StartBookingDraft)();

        $this->assertNull($draft->user_id);
        $this->assertSame(1, $draft->current_step);
        $this->assertSame([], $draft->completed_steps);
        $this->assertSame(1, $draft->version);
    }

    public function test_it_attaches_a_user_id_when_given_one(): void
    {
        $user = User::factory()->create();

        $draft = (new StartBookingDraft)(userId: $user->id);

        $this->assertSame($user->id, $draft->user_id);
    }

    public function test_it_records_an_audit_event(): void
    {
        $draft = (new StartBookingDraft)();

        $event = AuditEvent::query()->where('subject_id', $draft->id)->first();

        $this->assertNotNull($event);
        $this->assertSame('BOOKING_DRAFT_STARTED', $event->action);
        $this->assertSame('allowed', $event->outcome);
    }

    public function test_two_calls_create_two_distinct_drafts(): void
    {
        $first = (new StartBookingDraft)();
        $second = (new StartBookingDraft)();

        $this->assertNotSame($first->id, $second->id);
    }
}
