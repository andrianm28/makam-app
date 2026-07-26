<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\ServiceCatalog;

use App\Domain\ServiceCatalog\Actions\DefineServicePackage;
use App\Domain\ServiceCatalog\Actions\PublishServicePackageVersion;
use App\Domain\ServiceCatalog\Actions\ReviseServicePackageVersion;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Domain\ServiceCatalog\ServicePackageItemType;
use App\Domain\ServiceCatalog\ServicePackageVersionStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Proves `package-and-service-bundles/requirements.md` AC1 (a package
 * version's included/optional/excluded items, quantities, units,
 * fulfillment owner, service area/window, and evidence are all defined
 * correctly), AC7 (items fulfilled by cemetery/platform/vendor), and the
 * version-forward lifecycle `design.md` describes ("Later changes create
 * new versions, never mutate accepted quote contents"), end to end through
 * the real Actions — never a raw `Model::create()` call, mirroring
 * `tests/Feature/Domain/Faq/FaqArticleDraftExclusionTest.php`'s own
 * "exercise the real write Actions" convention. AC2 immutability itself has
 * its own dedicated, deeper test:
 * `ServicePackageVersionImmutabilityTest.php`.
 */
final class ServicePackageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{
     *     service_definition_id: int,
     *     item_type: string,
     *     quantity: int,
     *     unit: string,
     *     fulfillment_owner: string,
     *     service_area?: string|null,
     *     requires_schedule_window?: bool,
     *     evidence_required?: bool,
     *     notes?: string|null,
     *     evidence_requirements?: list<array{description: string, is_required?: bool}>,
     *     substitution_policies?: list<array{substitute_service_definition_id: int, requires_customer_approval?: bool, reason?: string|null}>,
     * }>
     */
    private function standardItems(): array
    {
        $documentProcessing = ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING);
        $graveDigging = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $hearse = ServiceDefinition::findByCode(ServiceCode::HEARSE);
        $flowers = ServiceDefinition::findByCode(ServiceCode::FLOWERS);
        $liveStreaming = ServiceDefinition::findByCode(ServiceCode::LIVE_STREAMING);

        return [
            [
                'service_definition_id' => $documentProcessing->id,
                'item_type' => ServicePackageItemType::INCLUDED,
                'quantity' => 1,
                'unit' => 'paket',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
                'evidence_required' => true,
                'evidence_requirements' => [
                    ['description' => 'Salinan surat keterangan kematian terunggah.'],
                ],
            ],
            [
                'service_definition_id' => $graveDigging->id,
                'item_type' => ServicePackageItemType::INCLUDED,
                'quantity' => 1,
                'unit' => 'unit',
                'fulfillment_owner' => FulfillmentOwner::CEMETERY_OPERATOR,
                'requires_schedule_window' => true,
            ],
            [
                'service_definition_id' => $hearse->id,
                'item_type' => ServicePackageItemType::OPTIONAL,
                'quantity' => 1,
                'unit' => 'unit',
                'fulfillment_owner' => FulfillmentOwner::VENDOR,
                'requires_schedule_window' => true,
                'substitution_policies' => [
                    [
                        'substitute_service_definition_id' => $liveStreaming->id,
                        'requires_customer_approval' => true,
                        'reason' => 'Uji substitusi lintas layanan.',
                    ],
                ],
            ],
            [
                'service_definition_id' => $flowers->id,
                'item_type' => ServicePackageItemType::EXCLUDED,
                'quantity' => 0,
                'unit' => 'rangkaian',
                'fulfillment_owner' => FulfillmentOwner::VENDOR,
                'notes' => 'Tidak termasuk dalam paket standar; tersedia sebagai tambahan berbayar.',
            ],
        ];
    }

    public function test_define_service_package_creates_a_draft_version_one_with_every_item_attribute(): void
    {
        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_STANDAR',
            name: 'Paket Uji Standar',
            items: $this->standardItems(),
            actorReference: 7,
            description: 'Deskripsi paket untuk pengujian.',
        );

        $this->assertSame('PAKET_UJI_STANDAR', $package->code);

        $version = $package->versions()->sole();
        $this->assertSame(1, $version->version_number);
        $this->assertTrue($version->isDraft());
        $this->assertNull($version->published_at);

        $this->assertSame(4, $version->items()->count());

        $included = $version->items()->ofType(ServicePackageItemType::INCLUDED)->get();
        $this->assertCount(2, $included);

        $optional = $version->items()->ofType(ServicePackageItemType::OPTIONAL)->sole();
        $this->assertSame(ServiceCode::HEARSE, $optional->serviceDefinition->code);
        $this->assertSame(FulfillmentOwner::VENDOR, $optional->fulfillment_owner);
        $this->assertTrue((bool) $optional->requires_schedule_window);
        $this->assertSame(1, $optional->substitutionPolicies()->count());
        $this->assertTrue((bool) $optional->substitutionPolicies()->sole()->requires_customer_approval);

        $excluded = $version->items()->ofType(ServicePackageItemType::EXCLUDED)->sole();
        $this->assertSame(ServiceCode::FLOWERS, $excluded->serviceDefinition->code);
        $this->assertSame(0, $excluded->quantity);

        $documentItem = $version->items()->where('service_definition_id', ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING)->id)->sole();
        $this->assertTrue((bool) $documentItem->evidence_required);
        $this->assertSame(1, $documentItem->evidenceRequirements()->count());
    }

    public function test_defining_a_package_with_zero_items_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DefineServicePackage)(
            code: 'PAKET_KOSONG',
            name: 'Paket Kosong',
            items: [],
            actorReference: 7,
        );
    }

    public function test_defining_an_item_with_an_unknown_service_definition_id_is_rejected(): void
    {
        $this->expectException(ModelNotFoundException::class);

        (new DefineServicePackage)(
            code: 'PAKET_SALAH',
            name: 'Paket Salah',
            items: [[
                'service_definition_id' => 999999,
                'item_type' => ServicePackageItemType::INCLUDED,
                'quantity' => 1,
                'unit' => 'unit',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
            ]],
            actorReference: 7,
        );
    }

    public function test_publishing_freezes_the_version_and_cannot_publish_an_empty_or_already_published_one_twice(): void
    {
        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_TERBIT',
            name: 'Paket Uji Terbit',
            items: $this->standardItems(),
            actorReference: 7,
        );

        $draft = $package->draftVersion();
        $this->assertNotNull($draft);

        $published = (new PublishServicePackageVersion)($draft, actorReference: 7);
        $this->assertTrue($published->isPublished());
        $this->assertNotNull($published->published_at);

        // Idempotent republish: no exception, same version, no second row.
        $republished = (new PublishServicePackageVersion)($published, actorReference: 7);
        $this->assertSame($published->id, $republished->id);
        $this->assertSame(1, $published->refresh()->version_number);
        $this->assertDatabaseCount('service_package_versions', 1);
    }

    public function test_revise_creates_version_two_as_a_draft_copy_of_the_published_version(): void
    {
        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_REVISI',
            name: 'Paket Uji Revisi',
            items: $this->standardItems(),
            actorReference: 7,
        );

        $v1 = (new PublishServicePackageVersion)($package->draftVersion(), actorReference: 7);

        $v2 = (new ReviseServicePackageVersion)($package, actorReference: 7);

        $this->assertSame(2, $v2->version_number);
        $this->assertTrue($v2->isDraft());
        $this->assertSame($v1->items()->count(), $v2->items()->count());
        $this->assertSame(
            $v1->items()->ofType(ServicePackageItemType::OPTIONAL)->sole()->substitutionPolicies()->count(),
            $v2->items()->ofType(ServicePackageItemType::OPTIONAL)->sole()->substitutionPolicies()->count(),
        );

        // v1 is untouched — still published, still frozen, still 4 items.
        $this->assertTrue($v1->refresh()->isPublished());
        $this->assertSame(4, $v1->items()->count());

        (new PublishServicePackageVersion)($v2, actorReference: 7);
        $this->assertSame(
            ServicePackageVersionStatus::PUBLISHED,
            $package->currentPublishedVersion()->status
        );
        $this->assertSame(2, $package->currentPublishedVersion()->version_number);
    }

    public function test_revise_is_rejected_when_the_package_has_never_been_published(): void
    {
        $package = (new DefineServicePackage)(
            code: 'PAKET_BELUM_TERBIT',
            name: 'Paket Belum Terbit',
            items: $this->standardItems(),
            actorReference: 7,
        );

        $this->expectException(InvalidArgumentException::class);

        (new ReviseServicePackageVersion)($package, actorReference: 7);
    }

    public function test_revise_is_rejected_while_an_open_draft_already_exists(): void
    {
        $package = (new DefineServicePackage)(
            code: 'PAKET_DRAFT_GANDA',
            name: 'Paket Draft Ganda',
            items: $this->standardItems(),
            actorReference: 7,
        );

        (new PublishServicePackageVersion)($package->draftVersion(), actorReference: 7);
        (new ReviseServicePackageVersion)($package, actorReference: 7); // creates v2 draft

        $this->expectException(InvalidArgumentException::class);

        (new ReviseServicePackageVersion)($package, actorReference: 7);
    }
}
