<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Filament\Admin\Resources\CemeteryResource\Pages\EditCemetery;
use App\Filament\Admin\Resources\CemeteryResource\RelationManagers\PackagesRelationManager;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Proves the two Task-4 behaviors of `CemeteryResource`:
 *
 * 1. `PackagesRelationManager` lists a cemetery's `cemetery_packages` rows
 *    and creates new ones inline (bound scope per the plan: list + inline
 *    create/edit only).
 * 2. Deleting a cemetery that still has `grave_records` is HONEST: the
 *    `grave_records.cemetery_id` RESTRICT foreign key would reject the
 *    DELETE, so the resource refuses up front with a danger notification
 *    instead of letting the exception escape as a 500 — and a cemetery
 *    without grave records still deletes normally.
 *
 * The seeded fixtures are reused via `CemeteryExampleData` (the ONE place
 * example data is defined) rather than re-created by hand: the Jakarta TPU
 * (`PACKAGE_CEMETERY_SLUGS[0]`) carries four package rows, and every
 * seeded cemetery carries at least one grave record.
 */
final class CemeteryResourcePackagesTest extends TestCase
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

    private function cemeteryBySlug(string $slug): Cemetery
    {
        return Cemetery::query()->where('slug', $slug)->sole();
    }

    public function test_packages_relation_manager_lists_and_creates_packages(): void
    {
        $this->admin();

        $cemetery = $this->cemeteryBySlug(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]);

        Livewire::test(PackagesRelationManager::class, [
            'ownerRecord' => $cemetery,
            'pageClass' => EditCemetery::class,
        ])
            ->assertCanSeeTableRecords($cemetery->packages()->get())
            ->callTableAction('create', data: [
                'name' => 'Makam Keluarga',
                'class_label' => null,
                'availability_status' => CemeteryPackageAvailabilityStatus::AVAILABLE,
                'description' => 'Contoh paket baru untuk uji relation manager.',
                'sort_order' => 5,
                'is_active' => true,
            ])
            ->assertHasNoTableActionErrors();

        $package = CemeteryPackage::query()
            ->where('cemetery_id', $cemetery->id)
            ->where('name', 'Makam Keluarga')
            ->sole();

        $this->assertSame(CemeteryPackageAvailabilityStatus::AVAILABLE, $package->availability_status);
        $this->assertSame(5, $package->sort_order);
        $this->assertTrue($package->is_active);
    }

    public function test_deleting_a_cemetery_with_grave_records_is_honest(): void
    {
        $this->admin();

        $cemetery = $this->cemeteryBySlug(CemeteryExampleData::OPEN_CEMETERY_SLUG);

        $this->assertGreaterThan(0, GraveRecord::query()->where('cemetery_id', $cemetery->id)->count());

        Livewire::test(EditCemetery::class, ['record' => $cemetery->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Makam tidak dapat dihapus.');

        $this->assertDatabaseHas('cemeteries', ['id' => $cemetery->id]);
    }

    public function test_deleting_a_cemetery_without_grave_records_succeeds(): void
    {
        $user = $this->admin();

        $cemetery = Cemetery::query()->create([
            'name' => 'TPU Tanpa Data Pemakaman',
            'slug' => 'tpu-tanpa-data-pemakaman',
            'type' => 'TPU',
            'city' => 'JAKARTA',
            'address' => 'Jl. Contoh Kota Jakarta No. 100',
            'publication_status' => 'draft',
        ]);

        Livewire::test(EditCemetery::class, ['record' => $cemetery->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Makam dihapus.');

        $this->assertDatabaseMissing('cemeteries', ['id' => $cemetery->id]);
    }
}
