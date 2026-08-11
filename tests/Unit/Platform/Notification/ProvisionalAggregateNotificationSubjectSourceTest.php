<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Models\User;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\ProvisionalAggregateNotificationSubjectSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * task-3-brief.md D3: the swappable seam that resolves a
 * `RecipientResolutionSubject` from an outbox envelope's aggregate
 * reference. Reads `booking_drafts` via the query builder, never via
 * `App\Domain\Booking\Models\BookingDraft` (see the class's own doc block).
 */
final class ProvisionalAggregateNotificationSubjectSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unmapped_aggregate_type_resolves_to_null_without_throwing(): void
    {
        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('grave_marker', 'anything');

        $this->assertNull($subject);
    }

    public function test_a_missing_booking_draft_row_resolves_to_null(): void
    {
        $subject = (new ProvisionalAggregateNotificationSubjectSource)
            ->subjectFor('booking_draft', '00000000-0000-0000-0000-000000000000');

        $this->assertNull($subject);
    }

    public function test_a_real_booking_draft_resolves_owner_and_cemetery_scope(): void
    {
        $user = User::factory()->create();
        $cemetery = $this->createCemetery();

        $draft = (new StartBookingDraft)(userId: $user->id);
        $draft->forceFill(['cemetery_id' => $cemetery->id])->save();

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('booking_draft', $draft->id);

        $this->assertNotNull($subject);
        $this->assertSame($user->id, $subject->ownerRef);
        $this->assertSame(ScopeEntityType::CEMETERY, $subject->scopeEntityType);
        $this->assertSame((string) $cemetery->id, (string) $subject->scopeEntityId);
    }

    public function test_an_anonymous_draft_has_no_owner_but_keeps_its_cemetery_scope(): void
    {
        $cemetery = $this->createCemetery();

        $draft = (new StartBookingDraft)(userId: null);
        $draft->forceFill(['cemetery_id' => $cemetery->id])->save();

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('booking_draft', $draft->id);

        $this->assertNotNull($subject);
        $this->assertNull($subject->ownerRef);
        $this->assertSame(ScopeEntityType::CEMETERY, $subject->scopeEntityType);
    }

    public function test_a_draft_with_no_cemetery_selected_yet_has_no_scope_entity(): void
    {
        $user = User::factory()->create();
        $draft = (new StartBookingDraft)(userId: $user->id);

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('booking_draft', $draft->id);

        $this->assertNotNull($subject);
        $this->assertSame($user->id, $subject->ownerRef);
        $this->assertFalse($subject->hasScopeEntity());
    }

    private function createCemetery(): Cemetery
    {
        static $sequence = 0;
        $sequence++;

        return Cemetery::create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => "Subject Source Test Cemetery {$sequence}",
            'slug' => "subject-source-test-cemetery-{$sequence}",
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Uji Coba Subjek',
        ]);
    }
}
