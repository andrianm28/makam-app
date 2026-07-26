<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\ServiceCatalog;

use App\Domain\ServiceCatalog\Actions\DefineServicePackage;
use App\Domain\ServiceCatalog\Actions\PublishServicePackageVersion;
use App\Domain\ServiceCatalog\Exceptions\PublishedServicePackageVersionIsImmutableException;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\Models\ServicePackageItem;
use App\Domain\ServiceCatalog\Models\ServicePackageVersion;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Domain\ServiceCatalog\ServicePackageItemType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE single most important test in this batch — `package-and-service-
 * bundles/requirements.md` AC2: "THE SYSTEM SHALL NOT allow modification
 * of a published package version." Mirrors the rigor `tests/Feature/
 * Domain/Faq/FaqArticleDraftExclusionTest.php` gives its own
 * single-most-important guarantee (AC6).
 *
 * Proves, against a REAL published version created through the real
 * Actions (not a raw `Model::create(['status' => 'published'])` fixture),
 * that every write path — the version row itself, and every item belonging
 * to it — refuses to save or delete, while the draft-side equivalent
 * operations succeed freely.
 */
final class ServicePackageVersionImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function publishedVersionWithOneItem(): ServicePackageVersion
    {
        $documentProcessing = ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING);

        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_IMUTABEL',
            name: 'Paket Uji Imutabel',
            items: [[
                'service_definition_id' => $documentProcessing->id,
                'item_type' => ServicePackageItemType::INCLUDED,
                'quantity' => 1,
                'unit' => 'paket',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
            ]],
            actorReference: 9,
        );

        return (new PublishServicePackageVersion)($package->draftVersion(), actorReference: 9);
    }

    public function test_a_draft_version_and_its_items_can_be_freely_saved_and_deleted(): void
    {
        $documentProcessing = ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING);

        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_DRAFT_BEBAS',
            name: 'Paket Uji Draft Bebas',
            items: [[
                'service_definition_id' => $documentProcessing->id,
                'item_type' => ServicePackageItemType::INCLUDED,
                'quantity' => 1,
                'unit' => 'paket',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
            ]],
            actorReference: 9,
        );

        $draft = $package->draftVersion();
        $draft->forceFill(['version_number' => 1])->save();
        $this->assertTrue(true); // no exception thrown above.

        $item = $draft->items()->sole();
        $item->forceFill(['quantity' => 2])->save();
        $this->assertSame(2, $item->refresh()->quantity);

        $item->delete();
        $this->assertDatabaseCount('service_package_items', 0);
    }

    public function test_saving_a_published_version_row_again_throws(): void
    {
        $version = $this->publishedVersionWithOneItem();

        $this->expectException(PublishedServicePackageVersionIsImmutableException::class);

        $version->forceFill(['published_at' => now()])->save();
    }

    public function test_deleting_a_published_version_row_throws(): void
    {
        $version = $this->publishedVersionWithOneItem();

        $this->expectException(PublishedServicePackageVersionIsImmutableException::class);

        $version->delete();
    }

    public function test_updating_an_item_belonging_to_a_published_version_throws(): void
    {
        $version = $this->publishedVersionWithOneItem();
        $item = $version->items()->sole();

        $this->expectException(PublishedServicePackageVersionIsImmutableException::class);

        $item->forceFill(['quantity' => 99])->save();
    }

    public function test_deleting_an_item_belonging_to_a_published_version_throws(): void
    {
        $version = $this->publishedVersionWithOneItem();
        $item = $version->items()->sole();

        $this->expectException(PublishedServicePackageVersionIsImmutableException::class);

        $item->delete();
    }

    public function test_inserting_a_new_item_directly_onto_a_published_version_throws(): void
    {
        $version = $this->publishedVersionWithOneItem();
        $graveDigging = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);

        $this->expectException(PublishedServicePackageVersionIsImmutableException::class);

        ServicePackageItem::create([
            'service_package_version_id' => $version->id,
            'service_definition_id' => $graveDigging->id,
            'item_type' => ServicePackageItemType::OPTIONAL,
            'quantity' => 1,
            'unit' => 'unit',
            'fulfillment_owner' => FulfillmentOwner::CEMETERY_OPERATOR,
        ]);
    }

    public function test_the_published_row_and_its_item_are_unchanged_after_every_rejected_attempt(): void
    {
        $version = $this->publishedVersionWithOneItem();
        $item = $version->items()->sole();

        foreach ([
            fn () => $version->forceFill(['published_at' => now()])->save(),
            fn () => $item->forceFill(['quantity' => 99])->save(),
        ] as $attempt) {
            try {
                $attempt();
            } catch (PublishedServicePackageVersionIsImmutableException) {
                // expected
            }
        }

        $this->assertSame(1, $item->refresh()->quantity);
        $this->assertDatabaseCount('service_package_versions', 1);
        $this->assertDatabaseCount('service_package_items', 1);
    }
}
