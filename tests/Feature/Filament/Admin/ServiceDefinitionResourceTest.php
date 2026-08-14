<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCatalogAuditActions;
use App\Domain\ServiceCatalog\ServiceCategory;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Filament\Admin\Resources\ServiceDefinitionResource;
use App\Filament\Admin\Resources\ServiceDefinitionResource\Pages\CreateServiceDefinition;
use App\Filament\Admin\Resources\ServiceDefinitionResource\Pages\EditServiceDefinition;
use App\Filament\Admin\Resources\ServiceDefinitionResource\RelationManagers\PriceVersionsRelationManager;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Feature coverage for the `ServiceDefinitionResource` admin surface
 * (admin-managed master data, Task 6) — the four-role access gate, the
 * canonical-field read-only / closed-list contract, the append-only price
 * versioning path, and the create/update audit rows.
 *
 * ---------------------------------------------------------------------------
 * Why every actor is granted a role first, and why `canAccess()` is asserted
 * directly
 * ---------------------------------------------------------------------------
 * `ServiceDefinitionResource::canAccess()` resolves `ActorContext` from the
 * container and answers the authorizer's question for THIS request's actor —
 * the same shape `FinanceReports::canAccess()` uses and the plan's Task 2
 * pattern (`CemeteryResource::canAccess()`). A bare customer is refused
 * (fail-closed), each of the four back-office roles passes, and a guest is
 * refused.
 *
 * The role grant goes through `Tests\Support\GrantsActorRoles` (the only
 * write path to `actor_role_assignments`), never a hand-built
 * `ActorContext`, so a failure anywhere in the grant -> reader -> adapter ->
 * context -> authorizer chain fails these tests rather than passing a
 * fabricated context.
 */
final class ServiceDefinitionResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_a_guest_cannot_access_the_service_definition_resource(): void
    {
        $this->assertFalse(ServiceDefinitionResource::canAccess());
    }

    public function test_a_bare_customer_cannot_access_the_service_definition_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse(ServiceDefinitionResource::canAccess());
    }

    public function test_the_four_back_office_roles_can_access_the_service_definition_resource(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(
                ServiceDefinitionResource::canAccess(),
                "Role [{$role}] must be able to access the service-definition resource."
            );
        }
    }

    public function test_service_code_is_disabled_on_edit_and_drawn_from_the_canonical_closed_lists(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $definition = ServiceDefinition::findByCode(ServiceCode::AMBULANCE);
        $this->assertNotNull($definition);

        Livewire::test(EditServiceDefinition::class, ['record' => $definition->getRouteKey()])
            ->assertFormFieldExists('code', function (Select $field): bool {
                return $field->getOptions() === array_combine(ServiceCode::KNOWN_CODES, ServiceCode::KNOWN_CODES);
            })
            ->assertFormFieldIsDisabled('code')
            ->assertFormFieldExists('fulfillment_owner', function (Select $field): bool {
                return $field->getOptions() === array_combine(FulfillmentOwner::KNOWN_OWNERS, FulfillmentOwner::KNOWN_OWNERS);
            })
            ->assertFormFieldExists('category', function (Select $field): bool {
                return $field->getOptions() === array_combine(ServiceCategory::KNOWN_CATEGORIES, ServiceCategory::KNOWN_CATEGORIES);
            });
    }

    public function test_creating_a_price_version_goes_through_the_append_only_action(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $definition = ServiceDefinition::findByCode(ServiceCode::AMBULANCE);
        $this->assertNotNull($definition);

        // Bootstrap: the dev-data seed gives every code a v1 placeholder
        // price, so this Action records the NEXT version on top of it.
        $this->assertSame(1, $definition->priceVersions()->max('version_number'));

        Livewire::test(PriceVersionsRelationManager::class, [
            'ownerRecord' => $definition,
            'pageClass' => EditServiceDefinition::class,
        ])
            ->callTableAction(
                'create',
                data: [
                    'amount' => '1250000.00',
                    'reason' => 'Penyesuaian tarif ambulans oleh operator.',
                ],
            )
            ->assertHasNoActionErrors();

        $definition->refresh();

        $versions = $definition->priceVersions()->orderBy('version_number')->get();
        $this->assertCount(2, $versions);

        $newVersion = $versions->last();
        $this->assertInstanceOf(PriceVersion::class, $newVersion);
        $this->assertSame(2, $newVersion->version_number);
        $this->assertSame('1250000.00', (string) $newVersion->amount);
        $this->assertSame('IDR', $newVersion->currency);
        $this->assertNull($newVersion->superseded_at);
        $this->assertTrue($definition->currentPriceVersion()?->is($newVersion) ?? false);

        // The append-only contract: the previous version is superseded, never
        // overwritten.
        $this->assertNotNull($versions->first()->superseded_at);

        // The write is audited by the Action itself.
        $this->assertDatabaseHas('audit_events', [
            'action' => ServiceCatalogAuditActions::PRICE_VERSION_RECORDED,
            'subject_type' => 'service_definition',
            'subject_id' => (string) $definition->id,
            'actor_ref' => (string) $user->id,
            'actor_role' => 'admin',
        ]);
    }

    public function test_an_authorized_admin_can_create_a_service_definition_with_an_audit_row(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        // All 12 canonical codes are seeded, so free the CATERING code first.
        // The canonical closed list is the only admission channel for `code`:
        // inventing a code is impossible (the select has no such option) and
        // re-registering a taken code is refused (the unique rule).
        $seeded = ServiceDefinition::findByCode(ServiceCode::CATERING);
        $this->assertNotNull($seeded);
        PriceVersion::query()
            ->where('priceable_type', ServiceDefinition::class)
            ->where('priceable_id', $seeded->id)
            ->delete();
        $seeded->delete();

        Livewire::test(CreateServiceDefinition::class)
            ->fillForm([
                'code' => ServiceCode::CATERING,
                'name' => 'Konsumsi',
                'category' => ServiceCategory::ADDITIONAL,
                'fulfillment_owner' => FulfillmentOwner::VENDOR,
                'requires_schedule' => true,
                'requires_manual_confirmation' => false,
                'is_active' => true,
                'description' => 'Katering untuk acara pemakaman.',
                'reason' => 'Registrasi ulang layanan katering oleh admin.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $definition = ServiceDefinition::findByCode(ServiceCode::CATERING);
        $this->assertNotNull($definition);
        $this->assertSame('Konsumsi', $definition->name);
        $this->assertSame(ServiceCategory::ADDITIONAL, $definition->category);
        $this->assertSame(FulfillmentOwner::VENDOR, $definition->fulfillment_owner);
        $this->assertTrue($definition->requires_schedule);
        $this->assertFalse($definition->requires_manual_confirmation);
        $this->assertTrue($definition->is_active);

        $event = AuditEvent::query()
            ->where('action', ServiceCatalogAuditActions::SERVICE_DEFINITION_CREATED)
            ->where('subject_id', (string) $definition->id)
            ->sole();

        $this->assertSame('service_definition', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
    }

    public function test_an_authorized_admin_can_update_a_service_definition_with_an_audit_row(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $definition = ServiceDefinition::findByCode(ServiceCode::HEARSE);
        $this->assertNotNull($definition);

        Livewire::test(EditServiceDefinition::class, ['record' => $definition->getRouteKey()])
            ->fillForm([
                'name' => 'Mobil Jenazah (Layanan Samedan)',
                'fulfillment_owner' => FulfillmentOwner::CEMETERY_OPERATOR,
                'requires_schedule' => true,
                'reason' => 'Layanan samedan memerlukan penjadwalan.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $definition->refresh();
        $this->assertSame('Mobil Jenazah (Layanan Samedan)', $definition->name);
        $this->assertSame(FulfillmentOwner::CEMETERY_OPERATOR, $definition->fulfillment_owner);
        $this->assertTrue($definition->requires_schedule);
        // The canonical code was not part of the submitted payload at all.
        $this->assertSame(ServiceCode::HEARSE, $definition->code);

        $event = AuditEvent::query()
            ->where('action', ServiceCatalogAuditActions::SERVICE_DEFINITION_UPDATED)
            ->where('subject_id', (string) $definition->id)
            ->sole();

        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
    }
}
