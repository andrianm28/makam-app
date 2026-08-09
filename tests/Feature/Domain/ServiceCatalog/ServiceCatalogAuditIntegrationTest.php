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
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Audit\SensitiveActions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves this batch's Audit-integration decision: every write Action calls
 * `App\Platform\Audit\Audit::record()`, and the emitted price-version action
 * is covered by the platform's mandatory-reason list.
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

    public function test_only_the_emitted_price_version_action_is_sensitive_listed(): void
    {
        foreach ([
            ServiceCatalogAuditActions::PACKAGE_DEFINED,
            ServiceCatalogAuditActions::PACKAGE_VERSION_PUBLISHED,
            ServiceCatalogAuditActions::PACKAGE_VERSION_REVISED,
        ] as $action) {
            $this->assertNotContains($action, SensitiveActions::ACTIONS, "{$action} should not be sensitive-listed.");
            $this->assertFalse(SensitiveActions::requiresReason($action), "{$action} should not require a mandatory reason.");
        }

        $this->assertContains(ServiceCatalogAuditActions::PRICE_VERSION_RECORDED, SensitiveActions::ACTIONS);
        $this->assertTrue(SensitiveActions::requiresReason(ServiceCatalogAuditActions::PRICE_VERSION_RECORDED));
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
            reason: 'Scheduled catalogue price update.',
        );

        $event = AuditEvent::query()->where('action', ServiceCatalogAuditActions::PRICE_VERSION_RECORDED)->sole();
        $this->assertSame('service_definition', $event->subject_type);
        $this->assertSame((string) $service->id, $event->subject_id);
        $this->assertSame((string) $priceVersion->version_number, $event->subject_version);
    }

    public function test_record_price_version_without_reason_is_rejected_and_rolled_back(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::CATERING);
        $priceVersionCount = $service->priceVersions()->count();

        $this->expectException(AuditReasonRequiredException::class);

        try {
            (new RecordServiceDefinitionPriceVersion)(
                serviceDefinition: $service,
                amount: '500000.00',
                actorReference: 11,
            );
        } finally {
            $this->assertSame($priceVersionCount, $service->priceVersions()->count());
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    /**
     * F19 (09 Aug 2026 ServiceCatalog Superpowers retrofit). All five tests
     * above prove the same direction — "mutation succeeds, therefore an audit
     * row exists". None proved the opposite one: "mutation FAILS, therefore
     * NO audit row exists". These four Actions do not use `Audit::wrap()`, so
     * `tests/Feature/Audit/AuditWrapTransactionTest.php` does not cover them
     * either; each relies on its own `DB::transaction` closure wrapping both
     * the writes and the `Audit::record()` call.
     *
     * Pre-fix, removing the `DB::transaction` wrapper from
     * `DefineServicePackage` left the whole suite green while the database
     * held an orphan package AND an audit row claiming a package was defined
     * that does not exist — the worst possible audit-trail failure, a record
     * of a mutation that never happened.
     */
    public function test_a_failed_define_writes_no_audit_row_and_leaves_no_package_behind(): void
    {
        try {
            (new DefineServicePackage)(
                code: 'PAKET_UJI_AUDIT_GAGAL',
                name: 'Paket Uji Audit Gagal',
                items: [[
                    'service_definition_id' => 999999,
                    'item_type' => ServicePackageItemType::INCLUDED,
                    'quantity' => 1,
                    'unit' => 'paket',
                    'fulfillment_owner' => FulfillmentOwner::PLATFORM,
                ]],
                actorReference: 11,
            );

            $this->fail('Defining a package with an unknown service_definition_id should have thrown.');
        } catch (ModelNotFoundException) {
            // expected
        }

        $this->assertSame(
            0,
            AuditEvent::query()->where('action', ServiceCatalogAuditActions::PACKAGE_DEFINED)->count(),
            'A rolled-back DefineServicePackage must leave no audit row behind.'
        );
        $this->assertDatabaseCount('service_packages', 0);
        $this->assertDatabaseCount('service_package_versions', 0);
    }
}
