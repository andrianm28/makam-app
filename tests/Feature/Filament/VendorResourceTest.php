<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorAvailability;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\Models\VendorUser;
use App\Domain\Marketplace\ProductCode;
use App\Filament\Admin\Resources\Vendors\Pages\CreateVendor;
use App\Filament\Admin\Resources\Vendors\Pages\EditVendor;
use App\Filament\Admin\Resources\Vendors\RelationManagers\AvailabilityRelationManager;
use App\Filament\Admin\Resources\Vendors\RelationManagers\ListingsRelationManager;
use App\Filament\Admin\Resources\Vendors\RelationManagers\MembersRelationManager;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Proves `VendorResource` and its three relation managers (members,
 * listings, availability) for the P2 admin data management lane:
 *
 * - the master-data gate: the four back-office roles pass, a bare
 *   customer and a guest fail closed (`MasterDataAdminAuthorizerContract`,
 *   the same shared authorizer every master-data resource uses);
 * - vendor create/update pair their `vendors` row with a
 *   `VENDOR_CREATED`/`VENDOR_UPDATED` audit row;
 * - members are add/revoke only — `VendorUser::create()` on add,
 *   `revoked_at` on revoke, never a delete;
 * - listing create accepts the REAL closed-list values
 *   (`AvailabilityMode::STOCKED`, `EvidenceRequirement::PHOTO`) — the
 *   values the model's `saving` hook and the Postgres CHECK constraints
 *   enforce — with an `LISTING_CREATED` audit row;
 * - availability create writes a schedule day with `AVAILABILITY_CREATED`;
 * - deleting a vendor that still has members is HONEST: refused up front
 *   with a danger notification, no state change, no audit row claiming a
 *   delete; deleting an empty vendor succeeds with `VENDOR_DELETED`.
 *
 * Every actor is granted `ActorRole::ADMIN` before exercising the write
 * paths, for the same reason the cemetery/FAQ resource tests do:
 * `VendorResource::canAccess()` refuses every actor without one of the
 * four back-office roles, so the grant is what lets these tests reach
 * their subject at all.
 */
final class VendorResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        return $user;
    }

    public function test_a_guest_cannot_access_the_vendor_resource(): void
    {
        $this->assertFalse(VendorResource::canAccess());
    }

    public function test_an_authenticated_customer_without_a_back_office_role_cannot_access_the_vendor_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse(VendorResource::canAccess());
    }

    public function test_the_four_back_office_roles_can_access_the_vendor_resource(): void
    {
        foreach ([
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
        ] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(VendorResource::canAccess(), "Expected role [{$role}] to access the vendor resource.");

            // Each iteration is a fresh user; drop the resolved context so
            // the next actor's roles are not cached from this one.
            $this->forgetResolvedActorContext();
        }
    }

    public function test_an_authorized_admin_can_create_a_vendor_with_an_audit_trail(): void
    {
        $user = $this->admin();

        Livewire::test(CreateVendor::class)
            ->fillForm([
                'name' => 'Toko Bunga Melati',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $vendor = Vendor::query()->where('name', 'Toko Bunga Melati')->sole();

        $this->assertTrue($vendor->is_active);

        $event = AuditEvent::query()
            ->where('action', 'VENDOR_CREATED')
            ->where('subject_id', (string) $vendor->id)
            ->sole();

        $this->assertSame('vendor', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
    }

    public function test_an_authorized_admin_can_update_a_vendor_with_an_audit_trail(): void
    {
        $user = $this->admin();

        $vendor = Vendor::query()->create([
            'name' => 'Batu Nisan Jaya',
            'is_active' => true,
        ]);

        Livewire::test(EditVendor::class, ['record' => $vendor->getRouteKey()])
            ->fillForm([
                'name' => 'Batu Nisan Jaya (Diubah)',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $vendor->refresh();

        $this->assertSame('Batu Nisan Jaya (Diubah)', $vendor->name);
        $this->assertFalse($vendor->is_active);

        $event = AuditEvent::query()
            ->where('action', 'VENDOR_UPDATED')
            ->where('subject_id', (string) $vendor->id)
            ->sole();

        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
    }

    public function test_members_relation_manager_adds_a_member_with_an_audit_row(): void
    {
        $user = $this->admin();

        $vendor = Vendor::query()->create([
            'name' => 'Toko Bunga Anggrek',
            'is_active' => true,
        ]);

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $vendor,
            'pageClass' => EditVendor::class,
        ])
            ->callTableAction('add', data: [
                'actor_identifier' => 'actor-77',
            ])
            ->assertHasNoTableActionErrors();

        $member = VendorUser::query()
            ->where('vendor_id', $vendor->id)
            ->where('actor_identifier', 'actor-77')
            ->sole();

        $this->assertNull($member->revoked_at);

        $event = AuditEvent::query()
            ->where('action', 'MEMBER_ADDED')
            ->where('subject_id', (string) $member->id)
            ->sole();

        $this->assertSame('vendor_user', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
    }

    public function test_members_relation_manager_revokes_a_member_without_deleting_the_row(): void
    {
        $user = $this->admin();

        $vendor = Vendor::query()->create([
            'name' => 'Toko Bunga Cempaka',
            'is_active' => true,
        ]);

        $member = VendorUser::query()->create([
            'vendor_id' => $vendor->id,
            'actor_identifier' => 'actor-88',
        ]);

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $vendor,
            'pageClass' => EditVendor::class,
        ])
            ->assertTableActionDoesNotExist('delete')
            ->callTableAction('revoke', $member)
            ->assertHasNoTableActionErrors();

        $member->refresh();

        // The row is KEPT — revocation is a soft state, never a delete.
        $this->assertNotNull($member->revoked_at);
        $this->assertSame(1, VendorUser::query()->where('vendor_id', $vendor->id)->count());

        $event = AuditEvent::query()
            ->where('action', 'MEMBER_REVOKED')
            ->where('subject_id', (string) $member->id)
            ->sole();

        $this->assertSame('vendor_user', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
    }

    public function test_listings_relation_manager_creates_a_listing_with_real_closed_list_values(): void
    {
        $user = $this->admin();

        $vendor = Vendor::query()->create([
            'name' => 'Bunga Duka Sejahtera',
            'is_active' => true,
        ]);

        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        $this->assertNotNull($product, 'The seeded FLOWER_BOARD product is missing.');

        Livewire::test(ListingsRelationManager::class, [
            'ownerRecord' => $vendor,
            'pageClass' => EditVendor::class,
        ])
            ->callTableAction('create', data: [
                'product_id' => (string) $product->id,
                'price_minor' => '750000',
                'availability_mode' => AvailabilityMode::STOCKED,
                'stock_quantity' => '5',
                'production_lead_time_days' => '2',
                'cancellation_policy' => 'Contoh kebijakan pembatalan: maksimal H-1.',
                'evidence_requirement' => EvidenceRequirement::PHOTO,
                'is_active' => true,
            ])
            ->assertHasNoTableActionErrors();

        $listing = VendorListing::query()
            ->where('vendor_id', $vendor->id)
            ->where('product_id', $product->id)
            ->sole();

        $this->assertSame(AvailabilityMode::STOCKED, $listing->availability_mode);
        $this->assertSame(EvidenceRequirement::PHOTO, $listing->evidence_requirement);
        $this->assertSame(750000, $listing->price_minor);
        $this->assertSame(5, $listing->stock_quantity);
        $this->assertTrue($listing->is_active);

        $event = AuditEvent::query()
            ->where('action', 'LISTING_CREATED')
            ->where('subject_id', (string) $listing->id)
            ->sole();

        $this->assertSame('vendor_listing', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
    }

    public function test_availability_relation_manager_creates_a_schedule_day_with_an_audit_row(): void
    {
        $user = $this->admin();

        $vendor = Vendor::query()->create([
            'name' => 'Toko Bunga Kenanga',
            'is_active' => true,
        ]);

        Livewire::test(AvailabilityRelationManager::class, [
            'ownerRecord' => $vendor,
            'pageClass' => EditVendor::class,
        ])
            ->callTableAction('create', data: [
                'available_date' => '2026-09-01',
                'capacity' => '4',
                'is_blocked' => false,
            ])
            ->assertHasNoTableActionErrors();

        $day = VendorAvailability::query()
            ->where('vendor_id', $vendor->id)
            ->whereDate('available_date', '2026-09-01')
            ->sole();

        $this->assertSame(4, $day->capacity);
        $this->assertFalse($day->is_blocked);

        $event = AuditEvent::query()
            ->where('action', 'AVAILABILITY_CREATED')
            ->where('subject_id', (string) $day->id)
            ->sole();

        $this->assertSame('vendor_availability', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
    }

    public function test_deleting_a_vendor_with_members_is_honest(): void
    {
        $this->admin();

        $vendor = Vendor::query()->create([
            'name' => 'Toko Bunga Dahlia',
            'is_active' => true,
        ]);

        VendorUser::query()->create([
            'vendor_id' => $vendor->id,
            'actor_identifier' => 'actor-99',
        ]);

        Livewire::test(EditVendor::class, ['record' => $vendor->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Vendor tidak dapat dihapus.');

        $this->assertDatabaseHas('vendors', ['id' => $vendor->id]);

        // A refused delete is not a mutation, so it must not leave an
        // audit row claiming one.
        $this->assertDatabaseMissing('audit_events', ['action' => 'VENDOR_DELETED']);
    }

    public function test_deleting_a_vendor_without_members_or_listings_succeeds(): void
    {
        $user = $this->admin();

        $vendor = Vendor::query()->create([
            'name' => 'Toko Bunga Tidak Terpakai',
            'is_active' => false,
        ]);

        Livewire::test(EditVendor::class, ['record' => $vendor->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Vendor dihapus.');

        $this->assertDatabaseMissing('vendors', ['id' => $vendor->id]);

        // A successful delete is a write like any other: it must leave a
        // `VENDOR_DELETED` audit row so the "every write records an audit
        // row" contract holds.
        $event = AuditEvent::query()
            ->where('action', 'VENDOR_DELETED')
            ->where('subject_id', (string) $vendor->id)
            ->sole();

        $this->assertSame('vendor', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
    }
}
