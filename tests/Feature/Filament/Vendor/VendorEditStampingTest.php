<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Vendor;

use App\Domain\Marketplace\Access\CurrentVendorScope;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Domain\Marketplace\Models\Vendor;
use App\Filament\Vendor\Resources\ServiceAreas\Pages\EditServiceArea;
use App\Models\User;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The write side of vendor scoping on the EDIT path.
 *
 * `VendorCreateStampingTest` covers `StampsCurrentVendor`, which only runs on
 * create. `vendor_id` is `$fillable` on the vendor-owned models, so an edit
 * that moves a record to another vendor is just as much a cross-vendor write
 * as a create that files it there — and it must be refused against the same
 * grant table. `Concerns\GuardsCurrentVendorOnEdit` re-reads the grants on
 * save, and this is the test that it does.
 *
 * Two layers are asserted:
 *
 *  - END-TO-END: posting a vendor the actor does NOT hold on an edit of a
 *    record under a granted vendor is refused and the record keeps its owner.
 *    In Filament 5.7.3 this refusal arrives as a form validation error, not a
 *    silent move — `Select::getInValidationRuleValues()` derives the `in:`
 *    values from the picker's grant-limited `options()` — which is what the
 *    whole-branch review needed proof of.
 *  - GUARD: `mutateFormDataBeforeSave()` throws an `AuthorizationException`
 *    for a forged `vendor_id` when invoked directly. This is the explicit,
 *    grant-table re-read that makes the edit write correct by construction
 *    even if the form layer's options-derived validation ever changes.
 *
 * `ServiceArea` stands in for all three edit paths. The three `EditRecord`
 * pages differ only in which resource they belong to; the guard is one shared
 * trait, so testing it once against a real page exercises the wiring and the
 * rule together.
 */
final class VendorEditStampingTest extends TestCase
{
    use RefreshDatabase;

    private string $ownVendorId;

    private string $secondVendorId;

    private string $thirdVendorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownVendorId = (string) Vendor::query()->create(['name' => 'Vendor Sendiri', 'is_active' => true])->id;
        $this->secondVendorId = (string) Vendor::query()->create(['name' => 'Vendor Kedua', 'is_active' => true])->id;
        $this->thirdVendorId = (string) Vendor::query()->create(['name' => 'Vendor Ketiga', 'is_active' => true])->id;

        $this->withoutVite();

        Filament::setCurrentPanel('vendor');
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    public function test_a_forged_vendor_id_on_edit_is_refused_and_the_record_keeps_its_owner(): void
    {
        // The attack: the actor is granted vendors A and B, is legitimately
        // editing a record filed under A, and posts C's id — a vendor they
        // hold no grant for. If the move went through, the record would land
        // in a catalogue the actor does not even see.
        $user = $this->actingAsVendorGrantedTo($this->ownVendorId);

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => $this->secondVendorId,
        ]);
        $this->app->forgetScopedInstances();

        $area = ServiceArea::query()->create([
            'vendor_id' => $this->ownVendorId,
            'area_code' => 'JKT-10',
            'area_label' => 'Jakarta Selatan',
            'delivery_fee_minor' => 25000,
            'is_active' => true,
        ]);

        Livewire::test(EditServiceArea::class, ['record' => $area->getRouteKey()])
            ->fillForm([
                'vendor_id' => $this->thirdVendorId,
                'area_label' => 'Bajakan',
            ])
            ->call('save')
            ->assertHasFormErrors(['vendor_id']);

        $this->assertSame($this->ownVendorId, $area->refresh()->vendor_id);
        $this->assertSame('Jakarta Selatan', $area->refresh()->area_label);
    }

    public function test_the_edit_save_guard_rejects_a_forged_vendor_id_from_the_grant_table(): void
    {
        // The guard's own decision, invoked without the form layer: an actor
        // granted A and B posts C's id and `mutateFormDataBeforeSave()` must
        // throw against the grant table, whatever the Select's options-derived
        // validation happens to do.
        $user = $this->actingAsVendorGrantedTo($this->ownVendorId);

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => $this->secondVendorId,
        ]);
        $this->app->forgetScopedInstances();

        $scope = app(CurrentVendorScope::class);

        $this->assertTrue($scope->allows($this->ownVendorId));
        $this->assertTrue($scope->allows($this->secondVendorId));
        $this->assertFalse($scope->allows($this->thirdVendorId));

        $page = new EditServiceArea;

        $this->expectException(AuthorizationException::class);

        $this->invadeSave($page, [
            'area_code' => 'JKT-12',
            'area_label' => 'Jakarta Timur',
            'vendor_id' => $this->thirdVendorId,
        ]);
    }

    public function test_an_actor_granted_two_vendors_may_move_a_record_between_those_two(): void
    {
        // The legitimate counterpart: moving A's record to B, where B is also
        // a grant of the same actor, is inside their scope either way and must
        // persist. Without this the guard would be blocking a legitimate move,
        // not only the forgery.
        $user = $this->actingAsVendorGrantedTo($this->ownVendorId);

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => $this->secondVendorId,
        ]);
        $this->app->forgetScopedInstances();

        $area = ServiceArea::query()->create([
            'vendor_id' => $this->ownVendorId,
            'area_code' => 'JKT-11',
            'area_label' => 'Jakarta Utara',
            'delivery_fee_minor' => 30000,
            'is_active' => true,
        ]);

        Livewire::test(EditServiceArea::class, ['record' => $area->getRouteKey()])
            ->fillForm(['vendor_id' => $this->secondVendorId])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->secondVendorId, $area->refresh()->vendor_id);
    }

    /**
     * `mutateFormDataBeforeSave()` is `protected` on Filament's `EditRecord`
     * (the trait overrides it at the same visibility), so a direct call needs
     * reflection — mirroring `VendorCreateStampingTest`'s `invadeMutate()`.
     * Calling it directly rather than only through a full Livewire round trip
     * is deliberate: the forged case throws, and asserting on the thrown
     * exception is a sharper assertion than a Livewire request whose
     * validation already refused the value before this hook runs.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function invadeSave(object $page, array $data): array
    {
        $method = new \ReflectionMethod($page, 'mutateFormDataBeforeSave');

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
