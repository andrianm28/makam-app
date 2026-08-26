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
use App\Platform\Audit\Models\AuditEvent;
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
 *    create/edit only — hence no delete action is offered), with every
 *    create/edit write paired to its `audit_events` row via
 *    `Audit::wrap()` (the same AC4 seam the cemetery pages themselves use).
 * 2. Deleting a cemetery that still has `grave_records` is HONEST: the
 *    `grave_records.cemetery_id` RESTRICT foreign key would reject the
 *    DELETE, so the resource refuses up front with a danger notification
 *    instead of letting the exception escape as a 500 — and a cemetery
 *    without grave records still deletes normally, leaving a
 *    `CEMETERY_DELETED` audit row behind (a refused delete leaves none).
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
        $user = $this->admin();

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

        $event = AuditEvent::query()
            ->where('action', 'CEMETERY_PACKAGE_CREATED')
            ->where('subject_id', (string) $package->id)
            ->sole();

        $this->assertSame('cemetery_package', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
    }

    /**
     * The real gap a 25 Aug 2026 scoping investigation found: no admin
     * write path existed anywhere for `cemetery_packages` PRICING (the
     * columns did not even exist). This is that path — an admin sets a
     * package price through the same relation-manager form already proven
     * above, it persists with the mandatory attribution field, and the
     * write leaves the same `audit_events` row every other package write
     * does (no new sensitive-action gate: `CemeteryPackageAuditActions`'
     * own doc block classifies package edits as content-editorial, and a
     * price edit is not a different kind of edit).
     */
    public function test_packages_relation_manager_sets_a_package_price_with_an_audit_row(): void
    {
        $user = $this->admin();

        $cemetery = $this->cemeteryBySlug(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]);

        Livewire::test(PackagesRelationManager::class, [
            'ownerRecord' => $cemetery,
            'pageClass' => EditCemetery::class,
        ])
            ->callTableAction('create', data: [
                'name' => 'Makam Tumpang Berharga',
                'class_label' => 'Kelas A',
                'availability_status' => CemeteryPackageAvailabilityStatus::AVAILABLE,
                'description' => 'Contoh paket dengan harga untuk uji relation manager.',
                'sort_order' => 6,
                'is_active' => true,
                'price_min' => '3000000',
                'price_max' => '5000000',
                'price_source' => 'Daftar harga pengelola, Agustus 2026',
            ])
            ->assertHasNoTableActionErrors();

        $package = CemeteryPackage::query()
            ->where('cemetery_id', $cemetery->id)
            ->where('name', 'Makam Tumpang Berharga')
            ->sole();

        $this->assertSame('3000000.00', $package->price_min);
        $this->assertSame('5000000.00', $package->price_max);
        $this->assertSame('IDR', $package->price_currency);
        $this->assertSame('Daftar harga pengelola, Agustus 2026', $package->price_source);
        // Never admin-entered — CemeteryPackage::booted() stamps it.
        $this->assertNotNull($package->price_effective_at);

        $event = AuditEvent::query()
            ->where('action', 'CEMETERY_PACKAGE_CREATED')
            ->where('subject_id', (string) $package->id)
            ->sole();

        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('allowed', $event->outcome);
    }

    /**
     * Editing an existing package's price re-stamps `price_effective_at`
     * (proved at the model level by `CemeteryPackagePricingTest`; this
     * proves it survives the real Filament edit path an admin actually
     * uses, and still leaves an audit row).
     */
    public function test_packages_relation_manager_edits_a_packages_price(): void
    {
        $this->admin();

        $cemetery = $this->cemeteryBySlug(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]);
        $package = $cemetery->packages()->firstOrFail();
        $this->assertNull($package->price_min);

        Livewire::test(PackagesRelationManager::class, [
            'ownerRecord' => $cemetery,
            'pageClass' => EditCemetery::class,
        ])
            ->callTableAction('edit', $package, data: [
                'name' => $package->name,
                'availability_status' => $package->availability_status,
                'sort_order' => $package->sort_order,
                'price_min' => '4000000',
                'price_max' => '6000000',
                'price_source' => 'Estimasi tim customer service',
            ])
            ->assertHasNoTableActionErrors();

        $package->refresh();
        $this->assertSame('4000000.00', $package->price_min);
        $this->assertSame('6000000.00', $package->price_max);
        $this->assertSame('Estimasi tim customer service', $package->price_source);
        $this->assertNotNull($package->price_effective_at);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'CEMETERY_PACKAGE_UPDATED',
            'subject_id' => (string) $package->id,
        ]);
    }

    public function test_packages_relation_manager_edits_a_package_with_an_audit_row(): void
    {
        $user = $this->admin();

        $cemetery = $this->cemeteryBySlug(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]);
        $package = $cemetery->packages()->firstOrFail();

        Livewire::test(PackagesRelationManager::class, [
            'ownerRecord' => $cemetery,
            'pageClass' => EditCemetery::class,
        ])
            ->callTableAction('edit', $package, data: [
                'name' => 'Makam Keluarga (Diubah)',
                'availability_status' => CemeteryPackageAvailabilityStatus::LIMITED,
                'sort_order' => 9,
            ])
            ->assertHasNoTableActionErrors();

        $package->refresh();
        $this->assertSame('Makam Keluarga (Diubah)', $package->name);
        $this->assertSame(CemeteryPackageAvailabilityStatus::LIMITED, $package->availability_status);
        $this->assertSame(9, $package->sort_order);

        $event = AuditEvent::query()
            ->where('action', 'CEMETERY_PACKAGE_UPDATED')
            ->where('subject_id', (string) $package->id)
            ->sole();

        $this->assertSame('cemetery_package', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
    }

    public function test_packages_relation_manager_does_not_offer_a_delete_action(): void
    {
        $this->admin();

        $cemetery = $this->cemeteryBySlug(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]);

        Livewire::test(PackagesRelationManager::class, [
            'ownerRecord' => $cemetery,
            'pageClass' => EditCemetery::class,
        ])
            ->assertTableActionDoesNotExist('delete');
    }

    public function test_a_customer_cannot_interact_with_the_packages_relation_manager(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $cemetery = $this->cemeteryBySlug(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]);

        $component = Livewire::test(PackagesRelationManager::class, [
            'ownerRecord' => $cemetery,
            'pageClass' => EditCemetery::class,
        ]);

        // The action-level `->authorize()` layer: neither the create nor
        // the edit action is offered to an unauthorized actor (hidden, not
        // merely disabled — `isHidden()` covers the authorization closure).
        $component
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit');

        // The `canViewForRecord()` layer (the relation manager's analogue
        // of the page-mount guard): the guard aborts 403 on the next wire
        // request, so no interactive request — an action submission
        // included — can ever mutate anything. (`Livewire::test()`'s mount
        // itself renders 200; Filament's own `CanAuthorizeAccess` trait
        // enforces on hydrate, verified against the installed v5.7.3.)
        $component->call('refresh')->assertForbidden();
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

        // A refused delete is not a mutation, so it must not leave an
        // audit row claiming one.
        $this->assertDatabaseMissing('audit_events', ['action' => 'CEMETERY_DELETED']);
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

        // A successful delete is a write like any other: it must leave a
        // `CEMETERY_DELETED` audit row so the "every create/update/delete
        // records an audit row" contract holds (the finding this test
        // regresses against).
        $event = AuditEvent::query()
            ->where('action', 'CEMETERY_DELETED')
            ->where('subject_id', (string) $cemetery->id)
            ->sole();

        $this->assertSame('cemetery', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
    }
}
