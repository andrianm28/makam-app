<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\ServiceCatalog\Actions\DefineServicePackage;
use App\Domain\ServiceCatalog\Actions\PublishServicePackageVersion;
use App\Domain\ServiceCatalog\Actions\ReviseServicePackageVersion;
use App\Domain\ServiceCatalog\Exceptions\PublishedServicePackageVersionIsImmutableException;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\Models\ServicePackage;
use App\Domain\ServiceCatalog\ServiceCatalogAuditActions;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Domain\ServiceCatalog\ServicePackageItemType;
use App\Domain\ServiceCatalog\ServicePackageVersionStatus;
use App\Filament\Admin\Resources\ServicePackages\Pages\ListServicePackages;
use App\Filament\Admin\Resources\ServicePackages\Pages\ViewServicePackage;
use App\Filament\Admin\Resources\ServicePackages\RelationManagers\VersionItemsRelationManager;
use App\Filament\Admin\Resources\ServicePackages\RelationManagers\VersionsRelationManager;
use App\Filament\Admin\Resources\ServicePackages\ServicePackageResource;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Feature coverage for the `ServicePackageResource` admin surface (P2
 * admin-data-management plan, Task 5, Lane C) — the four-role access gate,
 * the DefineServicePackage-routed create form with a repeatable items
 * schema, and the two relation managers whose mutations run exclusively
 * through the domain Actions (`DefineServicePackage`,
 * `PublishServicePackageVersion`, `ReviseServicePackageVersion`), which
 * self-audit.
 *
 * ---------------------------------------------------------------------------
 * Why the domain Actions are exercised directly in the relation-manager
 * tests
 * ---------------------------------------------------------------------------
 * The Versions relation manager's 'Terbitkan'/'Revisi' actions and the
 * VersionItems relation manager's create/edit route through the domain
 * Actions (or `Audit::wrap`-ed model writes) — the same shape
 * `ServicePackageLifecycleTest` and `ServiceCatalogAuditIntegrationTest`
 * already prove at the Domain layer. This test's job is the WIRING: that
 * the panel actions land the same rows and the same audit events an admin
 * would see.
 */
final class ServicePackageResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return array{service_definition_id: int, item_type: string, quantity: int, unit: string, fulfillment_owner: string}
     */
    private function itemSpec(): array
    {
        return [
            'service_definition_id' => ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING)->id,
            'item_type' => ServicePackageItemType::INCLUDED,
            'quantity' => 1,
            'unit' => 'paket',
            'fulfillment_owner' => FulfillmentOwner::PLATFORM,
        ];
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        return $user;
    }

    public function test_a_guest_cannot_access_the_service_package_resource(): void
    {
        $this->assertFalse(ServicePackageResource::canAccess());
    }

    public function test_a_bare_customer_cannot_access_the_service_package_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse(ServicePackageResource::canAccess());
    }

    public function test_the_four_back_office_roles_can_access_the_service_package_resource(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(
                ServicePackageResource::canAccess(),
                "Role [{$role}] must be able to access the service-package resource."
            );
        }
    }

    public function test_an_authorized_admin_can_create_a_package_with_one_item_through_the_list_page_action(): void
    {
        $user = $this->admin();

        Livewire::test(ListServicePackages::class)
            ->callAction('create', data: [
                'code' => 'PAKET_ADMIN_1',
                'name' => 'Paket Pemakaman Utama',
                'description' => 'Paket yang dibuat dari panel admin.',
                'items' => [
                    $this->itemSpec(),
                ],
            ])
            ->assertHasNoActionErrors();

        $package = ServicePackage::findByCode('PAKET_ADMIN_1');
        $this->assertNotNull($package);
        $this->assertSame('Paket Pemakaman Utama', $package->name);

        // The action returns the package with a DRAFT v1 and its items.
        $version = $package->versions()->sole();
        $this->assertSame(1, $version->version_number);
        $this->assertTrue($version->isDraft());
        $this->assertSame(1, $version->items()->count());

        $event = AuditEvent::query()
            ->where('action', ServiceCatalogAuditActions::PACKAGE_DEFINED)
            ->where('subject_id', (string) $package->id)
            ->sole();

        $this->assertSame('service_package', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
    }

    public function test_define_action_lands_a_draft_version_one_with_items_and_an_audit_row(): void
    {
        $user = $this->admin();

        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_DEFINE',
            name: 'Paket Uji Define',
            items: [$this->itemSpec()],
            actorReference: $user->id,
        );

        $version = $package->versions()->sole();
        $this->assertSame(1, $version->version_number);
        $this->assertTrue($version->isDraft());
        $this->assertSame(1, $version->items()->count());

        $event = AuditEvent::query()
            ->where('action', ServiceCatalogAuditActions::PACKAGE_DEFINED)
            ->where('subject_id', (string) $package->id)
            ->sole();

        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('1', $event->subject_version);
    }

    public function test_publishing_a_draft_marks_it_published_and_writes_an_audit_row(): void
    {
        $user = $this->admin();

        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_TERBIT',
            name: 'Paket Uji Terbit',
            items: [$this->itemSpec()],
            actorReference: $user->id,
        );

        $published = (new PublishServicePackageVersion)($package->draftVersion(), actorReference: $user->id);

        $this->assertTrue($published->isPublished());
        $this->assertNotNull($published->published_at);
        $this->assertSame(ServicePackageVersionStatus::PUBLISHED, $package->refresh()->currentPublishedVersion()->status);

        $event = AuditEvent::query()
            ->where('action', ServiceCatalogAuditActions::PACKAGE_VERSION_PUBLISHED)
            ->where('subject_id', (string) $published->id)
            ->sole();

        $this->assertSame(['previous_state' => 'draft', 'new_state' => 'published'], $event->metadata);
        $this->assertSame((string) $user->id, $event->actor_ref);
    }

    public function test_publishing_a_version_with_zero_items_is_rejected(): void
    {
        $user = $this->admin();

        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_KOSONG',
            name: 'Paket Uji Kosong',
            items: [$this->itemSpec()],
            actorReference: $user->id,
        );

        $draft = $package->draftVersion();
        $draft->items()->sole()->delete();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has zero items and cannot be published');

        (new PublishServicePackageVersion)($draft, actorReference: $user->id);
    }

    public function test_revise_creates_version_two_as_a_draft_copy_and_writes_an_audit_row(): void
    {
        $user = $this->admin();

        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_REVISI',
            name: 'Paket Uji Revisi',
            items: [$this->itemSpec()],
            actorReference: $user->id,
        );
        (new PublishServicePackageVersion)($package->draftVersion(), actorReference: $user->id);

        $v2 = (new ReviseServicePackageVersion)($package, actorReference: $user->id);

        $this->assertSame(2, $v2->version_number);
        $this->assertTrue($v2->isDraft());
        $this->assertSame(1, $v2->items()->count());

        $event = AuditEvent::query()
            ->where('action', ServiceCatalogAuditActions::PACKAGE_VERSION_REVISED)
            ->where('subject_id', (string) $v2->id)
            ->sole();

        $this->assertSame('2', $event->subject_version);
        $this->assertSame((string) $user->id, $event->actor_ref);
    }

    public function test_published_versions_items_are_immutable_and_direct_writes_throw(): void
    {
        $user = $this->admin();

        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_BEKU',
            name: 'Paket Uji Beku',
            items: [$this->itemSpec()],
            actorReference: $user->id,
        );
        $published = (new PublishServicePackageVersion)($package->draftVersion(), actorReference: $user->id);

        $item = $published->items()->sole();

        $this->expectException(PublishedServicePackageVersionIsImmutableException::class);

        $item->update(['quantity' => 2]);
    }

    public function test_an_authorized_admin_can_publish_a_draft_from_the_versions_relation_manager(): void
    {
        $user = $this->admin();

        $package = (new DefineServicePackage)(
            code: 'PAKET_RM_TERBIT',
            name: 'Paket RM Terbit',
            items: [$this->itemSpec()],
            actorReference: $user->id,
        );

        $draft = $package->draftVersion();

        Livewire::test(VersionsRelationManager::class, [
            'ownerRecord' => $package,
            'pageClass' => ViewServicePackage::class,
        ])
            ->callTableAction('publish', $draft)
            ->assertHasNoTableActionErrors();

        $this->assertTrue($draft->refresh()->isPublished());
        $this->assertDatabaseHas('audit_events', [
            'action' => ServiceCatalogAuditActions::PACKAGE_VERSION_PUBLISHED,
            'subject_id' => (string) $draft->id,
        ]);
    }

    public function test_an_authorized_admin_can_revise_from_the_versions_relation_manager_header_action(): void
    {
        $user = $this->admin();

        $package = (new DefineServicePackage)(
            code: 'PAKET_RM_REVISI',
            name: 'Paket RM Revisi',
            items: [$this->itemSpec()],
            actorReference: $user->id,
        );
        (new PublishServicePackageVersion)($package->draftVersion(), actorReference: $user->id);

        Livewire::test(VersionsRelationManager::class, [
            'ownerRecord' => $package,
            'pageClass' => ViewServicePackage::class,
        ])
            ->callTableAction('revise')
            ->assertHasNoTableActionErrors();

        $draft = $package->refresh()->draftVersion();
        $this->assertNotNull($draft);
        $this->assertSame(2, $draft->version_number);
        $this->assertSame(1, $draft->items()->count());

        $this->assertDatabaseHas('audit_events', [
            'action' => ServiceCatalogAuditActions::PACKAGE_VERSION_REVISED,
            'subject_id' => (string) $draft->id,
        ]);
    }

    public function test_the_versions_relation_manager_hides_publish_for_published_rows(): void
    {
        $user = $this->admin();

        $package = (new DefineServicePackage)(
            code: 'PAKET_RM_SEMBUNYI',
            name: 'Paket RM Sembunyi',
            items: [$this->itemSpec()],
            actorReference: $user->id,
        );
        $published = (new PublishServicePackageVersion)($package->draftVersion(), actorReference: $user->id);

        Livewire::test(VersionsRelationManager::class, [
            'ownerRecord' => $package,
            'pageClass' => ViewServicePackage::class,
        ])
            ->assertTableActionHidden('publish', $published);
    }

    public function test_an_authorized_admin_can_add_an_item_to_the_draft_version_from_the_items_relation_manager(): void
    {
        $user = $this->admin();

        $package = (new DefineServicePackage)(
            code: 'PAKET_RM_ITEM',
            name: 'Paket RM Item',
            items: [$this->itemSpec()],
            actorReference: $user->id,
        );

        $draft = $package->draftVersion();

        Livewire::test(VersionItemsRelationManager::class, [
            'ownerRecord' => $package,
            'pageClass' => ViewServicePackage::class,
        ])
            ->callTableAction('create', data: [
                'service_definition_id' => ServiceDefinition::findByCode(ServiceCode::HEARSE)->id,
                'item_type' => ServicePackageItemType::OPTIONAL,
                'quantity' => 1,
                'unit' => 'unit',
                'fulfillment_owner' => FulfillmentOwner::VENDOR,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, $draft->items()->count());

        $item = $draft->items()->where('item_type', ServicePackageItemType::OPTIONAL)->sole();

        $this->assertDatabaseHas('audit_events', [
            'action' => ServiceCatalogAuditActions::SERVICE_PACKAGE_ITEM_CREATED,
            'subject_id' => (string) $item->id,
            'actor_ref' => (string) $user->id,
        ]);
    }

    public function test_an_authorized_admin_can_edit_an_item_on_the_draft_version_with_an_audit_row(): void
    {
        $user = $this->admin();

        $package = (new DefineServicePackage)(
            code: 'PAKET_RM_EDIT',
            name: 'Paket RM Edit',
            items: [$this->itemSpec()],
            actorReference: $user->id,
        );

        $item = $package->draftVersion()->items()->sole();

        Livewire::test(VersionItemsRelationManager::class, [
            'ownerRecord' => $package,
            'pageClass' => ViewServicePackage::class,
        ])
            ->callTableAction('edit', $item, data: [
                'quantity' => 3,
                'unit' => 'paket',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(3, $item->refresh()->quantity);

        $this->assertDatabaseHas('audit_events', [
            'action' => ServiceCatalogAuditActions::SERVICE_PACKAGE_ITEM_UPDATED,
            'subject_id' => (string) $item->id,
            'actor_ref' => (string) $user->id,
        ]);
    }

    public function test_deleting_a_package_that_still_has_a_published_version_is_refused_honestly(): void
    {
        $user = $this->admin();

        $package = (new DefineServicePackage)(
            code: 'PAKET_UTUH',
            name: 'Paket Utuh',
            items: [$this->itemSpec()],
            actorReference: $user->id,
        );
        (new PublishServicePackageVersion)($package->draftVersion(), actorReference: $user->id);

        Livewire::test(ViewServicePackage::class, ['record' => $package->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Paket tidak dapat dihapus.');

        $this->assertDatabaseHas('service_packages', ['id' => $package->id]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'SERVICE_PACKAGE_DELETED']);
    }

    public function test_a_package_with_only_draft_versions_can_be_deleted_with_an_audit_row(): void
    {
        $user = $this->admin();

        $package = (new DefineServicePackage)(
            code: 'PAKET_LAKUKAN',
            name: 'Paket Lakukan',
            items: [$this->itemSpec()],
            actorReference: $user->id,
        );

        Livewire::test(ViewServicePackage::class, ['record' => $package->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Paket dihapus.');

        $this->assertDatabaseMissing('service_packages', ['id' => $package->id]);

        $event = AuditEvent::query()
            ->where('action', 'SERVICE_PACKAGE_DELETED')
            ->where('subject_id', (string) $package->id)
            ->sole();

        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
    }
}
