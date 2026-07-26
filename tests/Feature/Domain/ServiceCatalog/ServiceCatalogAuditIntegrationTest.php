<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\ServiceCatalog;

use App\Domain\ServiceCatalog\Actions\DefineServicePackage;
use App\Domain\ServiceCatalog\Actions\PublishServicePackageVersion;
use App\Domain\ServiceCatalog\Actions\RecordServiceDefinitionPriceVersion;
use App\Domain\ServiceCatalog\Actions\ReviseServicePackageVersion;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCatalogAuditActions;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Domain\ServiceCatalog\ServicePackageItemType;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Audit\SensitiveActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves this batch's Audit-integration decision: every write Action calls
 * `App\Platform\Audit\Audit::record()`, but none of the four action names
 * are on `SensitiveActions::ACTIONS` — the judgement call
 * `App\Domain\ServiceCatalog\ServiceCatalogAuditActions`'s own doc block
 * documents. Mirrors `tests/Feature/Domain/Faq/FaqAuditIntegrationTest.php`'s
 * own shape.
 */
final class ServiceCatalogAuditIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function itemSpec(): array
    {
        return [[
            'service_definition_id' => ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING)->id,
            'item_type' => ServicePackageItemType::INCLUDED,
            'quantity' => 1,
            'unit' => 'paket',
            'fulfillment_owner' => FulfillmentOwner::PLATFORM,
        ]];
    }

    public function test_none_of_the_service_catalog_audit_actions_are_sensitive_listed(): void
    {
        foreach ([
            ServiceCatalogAuditActions::PACKAGE_DEFINED,
            ServiceCatalogAuditActions::PACKAGE_VERSION_PUBLISHED,
            ServiceCatalogAuditActions::PACKAGE_VERSION_REVISED,
            ServiceCatalogAuditActions::PRICE_VERSION_RECORDED,
        ] as $action) {
            $this->assertNotContains($action, SensitiveActions::ACTIONS, "{$action} should not be sensitive-listed.");
            $this->assertFalse(SensitiveActions::requiresReason($action), "{$action} should not require a mandatory reason.");
        }
    }

    public function test_define_service_package_writes_an_audit_row(): void
    {
        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_AUDIT_DEFINE',
            name: 'Paket Uji Audit Define',
            items: $this->itemSpec(),
            actorReference: 11,
            actorRole: 'catalog-admin',
        );

        $event = AuditEvent::query()->where('action', ServiceCatalogAuditActions::PACKAGE_DEFINED)->sole();
        $this->assertSame('11', $event->actor_ref);
        $this->assertSame('catalog-admin', $event->actor_role);
        $this->assertSame('service_package', $event->subject_type);
        $this->assertSame((string) $package->id, $event->subject_id);
        $this->assertSame('1', $event->subject_version);
        $this->assertSame(['new_state' => 'draft'], $event->metadata);
        $this->assertNull($event->reason);
    }

    public function test_publish_writes_an_audit_row_with_previous_and_new_state_metadata(): void
    {
        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_AUDIT_PUBLISH',
            name: 'Paket Uji Audit Publish',
            items: $this->itemSpec(),
            actorReference: 11,
        );

        $published = (new PublishServicePackageVersion)($package->draftVersion(), actorReference: 11);

        $event = AuditEvent::query()->where('action', ServiceCatalogAuditActions::PACKAGE_VERSION_PUBLISHED)->sole();
        $this->assertSame((string) $published->id, $event->subject_id);
        $this->assertSame(['previous_state' => 'draft', 'new_state' => 'published'], $event->metadata);
    }

    public function test_republishing_an_already_published_version_writes_no_second_audit_row(): void
    {
        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_AUDIT_IDEMPOTEN',
            name: 'Paket Uji Audit Idempoten',
            items: $this->itemSpec(),
            actorReference: 11,
        );

        $published = (new PublishServicePackageVersion)($package->draftVersion(), actorReference: 11);
        (new PublishServicePackageVersion)($published, actorReference: 11);

        $this->assertSame(
            1,
            AuditEvent::query()->where('action', ServiceCatalogAuditActions::PACKAGE_VERSION_PUBLISHED)->count()
        );
    }

    public function test_revise_writes_an_audit_row_referencing_the_new_version(): void
    {
        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_AUDIT_REVISI',
            name: 'Paket Uji Audit Revisi',
            items: $this->itemSpec(),
            actorReference: 11,
        );
        (new PublishServicePackageVersion)($package->draftVersion(), actorReference: 11);

        $v2 = (new ReviseServicePackageVersion)($package, actorReference: 11);

        $event = AuditEvent::query()->where('action', ServiceCatalogAuditActions::PACKAGE_VERSION_REVISED)->sole();
        $this->assertSame((string) $v2->id, $event->subject_id);
        $this->assertSame('2', $event->subject_version);
    }

    public function test_record_price_version_writes_an_audit_row(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::CATERING);

        $priceVersion = (new RecordServiceDefinitionPriceVersion)(
            serviceDefinition: $service,
            amount: '500000.00',
            actorReference: 11,
        );

        $event = AuditEvent::query()->where('action', ServiceCatalogAuditActions::PRICE_VERSION_RECORDED)->sole();
        $this->assertSame('service_definition', $event->subject_type);
        $this->assertSame((string) $service->id, $event->subject_id);
        $this->assertSame((string) $priceVersion->version_number, $event->subject_version);
    }
}
