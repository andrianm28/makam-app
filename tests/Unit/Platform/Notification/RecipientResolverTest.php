<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Contracts\NotificationMatrixSource;
use App\Platform\Notification\Recipient;
use App\Platform\Notification\RecipientResolutionSubject;
use App\Platform\Notification\RecipientResolver;
use App\Platform\Notification\RecipientRole;
use App\Platform\Notification\RecipientSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * `RecipientResolver` — Task 2 of the L2 `platform-notifications` lane.
 * Reads `docs/contracts/notification-matrix.md` (via `NotificationMatrixSource`)
 * for an event's recipient classes, then resolves each class from record
 * scope (`ScopeAssignmentResolver::actorsForEntity()` + the provisional
 * role seam) or from the record owner reference for customers.
 *
 * Uses the real `docs/contracts/notification-matrix.md` (not a fixture
 * copy), so these tests assert against its actual current rows. If a
 * future change to that document alters a row this file depends on, the
 * failure is exactly the signal that this file needs updating too. Ruling
 * 4 (`docs/superpowers/plans/2026-08-10-wave1a-notifications-decisions.md`)
 * added the "Case manager" and "Finance" columns additively — every
 * existing row/column/cell this file already depended on is unchanged.
 *
 * Deviation from the brief's Step 5 example test list, approved by the
 * `lane-notifications` coordinator mid-task (see `task-2-report.md`
 * "Deviations" section): "booking-submitted resolves customer + location
 * operator + admin" is not achievable from real data, because nothing in
 * this codebase links a booking to a `business_entity` scope — there is no
 * `business_entities` table and no FK from `booking_drafts` to one. Admin
 * resolution is instead proven generically, with a subject scoped directly
 * to a `business_entity`.
 */
final class RecipientResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_submitted_resolves_customer_and_cemetery_operator(): void
    {
        ScopeAssignment::query()->create(['actor_identifier' => 'operator-1', 'entity_type' => ScopeEntityType::CEMETERY, 'entity_id' => '10']);

        $subject = new RecipientResolutionSubject(ownerRef: 'customer-1', scopeEntityType: ScopeEntityType::CEMETERY, scopeEntityId: '10');

        $set = $this->resolver()->resolve('Booking submitted', $subject);

        $this->assertRecipientsMatch([
            ['actor_ref' => 'customer-1', 'actor_role' => RecipientRole::CUSTOMER, 'scope_entity_type' => null, 'scope_entity_id' => null],
            ['actor_ref' => 'operator-1', 'actor_role' => RecipientRole::CEMETERY_OPERATOR, 'scope_entity_type' => ScopeEntityType::CEMETERY, 'scope_entity_id' => '10'],
        ], $set);
    }

    public function test_a_business_entity_scoped_subject_resolves_platform_admin_recipients(): void
    {
        // Proves the admin path is real, even though no current record
        // (booking, order, ...) can supply a business_entity scope — see
        // this class's own doc block.
        ScopeAssignment::query()->create(['actor_identifier' => 'admin-1', 'entity_type' => ScopeEntityType::BUSINESS_ENTITY, 'entity_id' => '1']);

        // "Booking submitted"'s Admin platform column is IN_APP (not none).
        $subject = new RecipientResolutionSubject(ownerRef: null, scopeEntityType: ScopeEntityType::BUSINESS_ENTITY, scopeEntityId: '1');

        $set = $this->resolver()->resolve('Booking submitted', $subject);

        $this->assertRecipientsMatch([
            ['actor_ref' => 'admin-1', 'actor_role' => RecipientRole::PLATFORM_ADMIN, 'scope_entity_type' => ScopeEntityType::BUSINESS_ENTITY, 'scope_entity_id' => '1'],
        ], $set);
    }

    public function test_a_vendor_scoped_subject_resolves_only_the_assigned_vendor(): void
    {
        ScopeAssignment::query()->create(['actor_identifier' => 'vendor-1', 'entity_type' => ScopeEntityType::VENDOR, 'entity_id' => '5']);

        // "Payment received"'s Vendor column is "Vendor when allocated"
        // (not none). No ownerRef here, so no customer recipient — this
        // test isolates the vendor path.
        $subject = new RecipientResolutionSubject(ownerRef: null, scopeEntityType: ScopeEntityType::VENDOR, scopeEntityId: '5');

        $set = $this->resolver()->resolve('Payment received', $subject);

        $this->assertRecipientsMatch([
            ['actor_ref' => 'vendor-1', 'actor_role' => RecipientRole::VENDOR, 'scope_entity_type' => ScopeEntityType::VENDOR, 'scope_entity_id' => '5'],
        ], $set);
    }

    public function test_cross_scope_leakage_a_vendor_scoped_to_a_different_entity_resolves_to_nothing(): void
    {
        // This actor is a vendor, but for entity id 6 — a different order's
        // vendor allocation, not the one this event's record references.
        ScopeAssignment::query()->create(['actor_identifier' => 'other-vendor', 'entity_type' => ScopeEntityType::VENDOR, 'entity_id' => '6']);

        $subject = new RecipientResolutionSubject(ownerRef: null, scopeEntityType: ScopeEntityType::VENDOR, scopeEntityId: '5');

        $set = $this->resolver()->resolve('Payment received', $subject);

        $this->assertTrue($set->isEmpty());
    }

    public function test_a_matrix_row_that_is_none_everywhere_resolves_empty(): void
    {
        ScopeAssignment::query()->create(['actor_identifier' => 'operator-1', 'entity_type' => ScopeEntityType::CEMETERY, 'entity_id' => '10']);

        $subject = new RecipientResolutionSubject(ownerRef: 'customer-1', scopeEntityType: ScopeEntityType::CEMETERY, scopeEntityId: '10');

        $set = $this->resolver()->resolve('Booking draft created', $subject);

        $this->assertTrue($set->isEmpty());
    }

    public function test_an_anonymous_booking_draft_yields_no_customer_recipient(): void
    {
        ScopeAssignment::query()->create(['actor_identifier' => 'operator-1', 'entity_type' => ScopeEntityType::CEMETERY, 'entity_id' => '10']);

        // booking_drafts.user_id is nullable — an anonymous draft has none.
        $subject = new RecipientResolutionSubject(ownerRef: null, scopeEntityType: ScopeEntityType::CEMETERY, scopeEntityId: '10');

        $set = $this->resolver()->resolve('Booking submitted', $subject);

        $this->assertRecipientsMatch([
            ['actor_ref' => 'operator-1', 'actor_role' => RecipientRole::CEMETERY_OPERATOR, 'scope_entity_type' => ScopeEntityType::CEMETERY, 'scope_entity_id' => '10'],
        ], $set);
    }

    public function test_a_revoked_grant_never_resolves(): void
    {
        ScopeAssignment::query()->create(['actor_identifier' => 'operator-1', 'entity_type' => ScopeEntityType::CEMETERY, 'entity_id' => '10'])->revoke();

        $subject = new RecipientResolutionSubject(ownerRef: null, scopeEntityType: ScopeEntityType::CEMETERY, scopeEntityId: '10');

        $set = $this->resolver()->resolve('Booking submitted', $subject);

        $this->assertTrue($set->isEmpty());
    }

    public function test_an_order_event_resolves_empty_per_ruling_6(): void
    {
        // No OrderWorkflow domain model exists (app/Domain/OrderWorkflow/
        // has only .gitkeep), so no caller can supply a real owner
        // reference or scope entity for an order event today — the
        // resolver correctly produces nothing from an empty subject.
        $subject = new RecipientResolutionSubject(ownerRef: null, scopeEntityType: null, scopeEntityId: null);

        $set = $this->resolver()->resolve('Order processing', $subject);

        $this->assertTrue($set->isEmpty());
    }

    public function test_a_tbd_case_manager_cell_never_resolves_a_recipient(): void
    {
        // Ruling 4's approved refinement (`docs/superpowers/plans/
        // 2026-08-10-wave1a-notifications-decisions.md`): every "Case
        // manager" cell in the matrix is the literal `TBD`, not `none`.
        // A real grant exists here — the security property under test is
        // that an undecided policy still resolves to nothing, even though
        // a recipient could technically be derived.
        ScopeAssignment::query()->create(['actor_identifier' => 'case-manager-1', 'entity_type' => ScopeEntityType::CASE_RECORD, 'entity_id' => '7']);

        $subject = new RecipientResolutionSubject(ownerRef: null, scopeEntityType: ScopeEntityType::CASE_RECORD, scopeEntityId: '7');

        $set = $this->resolver()->resolve('Booking submitted', $subject);

        $this->assertTrue($set->isEmpty());
    }

    public function test_an_unknown_event_resolves_empty_and_logs_without_throwing(): void
    {
        Log::shouldReceive('warning')->once();

        $subject = new RecipientResolutionSubject(ownerRef: 'customer-1', scopeEntityType: null, scopeEntityId: null);

        $set = $this->resolver()->resolve('Not a real matrix event', $subject);

        $this->assertTrue($set->isEmpty());
    }

    private function resolver(): RecipientResolver
    {
        return $this->app->make(RecipientResolver::class);
    }

    /**
     * @param  list<array{actor_ref: int|string, actor_role: string, scope_entity_type: ?string, scope_entity_id: int|string|null}>  $expected
     */
    private function assertRecipientsMatch(array $expected, RecipientSet $set): void
    {
        $actual = array_map(
            static fn (Recipient $recipient): array => [
                'actor_ref' => $recipient->actorRef,
                'actor_role' => $recipient->actorRole,
                'scope_entity_type' => $recipient->scopeEntityType,
                'scope_entity_id' => $recipient->scopeEntityId,
            ],
            $set->all(),
        );

        $sorter = static fn (array $a, array $b): int => [$a['actor_role'], $a['actor_ref']] <=> [$b['actor_role'], $b['actor_ref']];
        usort($actual, $sorter);
        usort($expected, $sorter);

        $this->assertSame($expected, $actual);
    }
}
