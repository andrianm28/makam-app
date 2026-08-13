<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Vendor;

use App\Domain\Marketplace\Access\CurrentVendorScope;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Domain\Marketplace\Models\Vendor;
use App\Filament\Vendor\Resources\ServiceAreas\Pages\CreateServiceArea;
use App\Models\User;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The write side of vendor scoping.
 *
 * `VendorPanelScopingTest` proves an actor cannot SEE another vendor's records.
 * That says nothing about whether they can CREATE one inside another vendor —
 * a row they would immediately lose sight of, but which would be live in the
 * other vendor's catalogue. `Concerns\StampsCurrentVendor` is what closes that,
 * and this is the test that it does.
 *
 * `ServiceArea` stands in for all three create paths. The three
 * `CreateRecord` pages differ only in which resource they belong to; the
 * stamping logic is one shared trait, so testing it once against a real page
 * and separately against the trait's decision function covers both the wiring
 * and the rule. `VendorPanelScopingTest`'s structural test is what guarantees
 * no create page quietly stops using the trait.
 */
final class VendorCreateStampingTest extends TestCase
{
    use RefreshDatabase;

    private string $ownVendorId;

    private string $otherVendorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownVendorId = (string) Vendor::query()->create(['name' => 'Vendor Sendiri', 'is_active' => true])->id;
        $this->otherVendorId = (string) Vendor::query()->create(['name' => 'Vendor Lain', 'is_active' => true])->id;

        Filament::setCurrentPanel('vendor');
    }

    public function test_a_created_record_is_stamped_with_the_actors_sole_granted_vendor(): void
    {
        $this->actingAsVendorGrantedTo($this->ownVendorId);

        Livewire::test(CreateServiceArea::class)
            ->fillForm([
                'area_code' => 'JKT-01',
                'area_label' => 'Jakarta Pusat',
                'delivery_fee_minor' => 25000,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = ServiceArea::query()->where('area_code', 'JKT-01')->sole();

        $this->assertSame($this->ownVendorId, $created->vendor_id);
    }

    public function test_a_forged_vendor_id_in_the_payload_is_refused(): void
    {
        // The attack this trait exists for: the actor is granted vendor A and
        // posts vendor B's id for a field the server never offered them. The
        // rendered options list cannot be the control, because Livewire will
        // carry whatever the client sets — so the refusal has to come from
        // re-reading the grant table.
        $this->actingAsVendorGrantedTo($this->ownVendorId);

        $scope = app(CurrentVendorScope::class);

        $this->assertFalse($scope->allows($this->otherVendorId));
        $this->assertTrue($scope->allows($this->ownVendorId));

        $page = new CreateServiceArea;

        $this->expectException(AuthorizationException::class);

        $this->invadeMutate($page, [
            'area_code' => 'JKT-02',
            'area_label' => 'Jakarta Selatan',
            'vendor_id' => $this->otherVendorId,
        ]);
    }

    public function test_an_actor_with_no_grant_cannot_create_anything(): void
    {
        $this->actingAs(User::factory()->create());
        $this->app->forgetScopedInstances();

        $page = new CreateServiceArea;

        $this->expectException(AuthorizationException::class);

        $this->invadeMutate($page, [
            'area_code' => 'JKT-03',
            'area_label' => 'Jakarta Barat',
        ]);
    }

    public function test_an_actor_granted_two_vendors_must_name_one(): void
    {
        // `defaultVendorId()` returns null for a multi-grant actor rather than
        // guessing, so an omitted vendor_id is refused instead of being filed
        // under whichever grant happened to sort first.
        $user = $this->actingAsVendorGrantedTo($this->ownVendorId);

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => $this->otherVendorId,
        ]);
        $this->app->forgetScopedInstances();

        $page = new CreateServiceArea;

        $this->expectException(AuthorizationException::class);

        $this->invadeMutate($page, [
            'area_code' => 'JKT-04',
            'area_label' => 'Jakarta Timur',
        ]);
    }

    public function test_an_actor_granted_two_vendors_may_name_either_of_them(): void
    {
        $user = $this->actingAsVendorGrantedTo($this->ownVendorId);

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => $this->otherVendorId,
        ]);
        $this->app->forgetScopedInstances();

        $page = new CreateServiceArea;

        $stamped = $this->invadeMutate($page, [
            'area_code' => 'JKT-05',
            'area_label' => 'Jakarta Utara',
            'vendor_id' => $this->otherVendorId,
        ]);

        // Both grants are genuinely the actor's, so naming the second one is a
        // legitimate create and not the forgery the test above covers.
        $this->assertSame($this->otherVendorId, $stamped['vendor_id']);
    }

    /**
     * `mutateFormDataBeforeCreate()` is `protected` on Filament's `CreateRecord`
     * (this trait overrides it at the same visibility), so a direct call needs
     * reflection. Calling it directly rather than only through a full Livewire
     * round trip is deliberate: the refusal cases throw, and asserting on the
     * thrown exception is a sharper assertion than asserting a Livewire request
     * produced no row.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function invadeMutate(object $page, array $data): array
    {
        $method = new \ReflectionMethod($page, 'mutateFormDataBeforeCreate');

        /** @var array<string, mixed> $result */
        $result = $method->invoke($page, $data);

        return $result;
    }

    private function actingAsVendorGrantedTo(string $vendorId): User
    {
        $user = User::factory()->create();

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => $vendorId,
        ]);

        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        return $user;
    }
}
