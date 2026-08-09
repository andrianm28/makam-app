<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\ServiceCatalog;

use App\Domain\ServiceCatalog\Actions\DefineServicePackage;
use App\Domain\ServiceCatalog\Actions\PublishServicePackageVersion;
use App\Domain\ServiceCatalog\Exceptions\PublishedServicePackageVersionIsImmutableException;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\EvidenceRequirement;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\Models\ServicePackage;
use App\Domain\ServiceCatalog\Models\ServicePackageItem;
use App\Domain\ServiceCatalog\Models\ServicePackageVersion;
use App\Domain\ServiceCatalog\Models\SubstitutionPolicy;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Domain\ServiceCatalog\ServicePackageItemType;
use App\Domain\ServiceCatalog\ServicePackageVersionStatus;
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

    /**
     * A published version whose single item carries one `SubstitutionPolicy`
     * and one `EvidenceRequirement` — the fixture the per-item child guards
     * need. Built through the real Actions, same convention as
     * `publishedVersionWithOneItem()`.
     */
    private function publishedVersionWithOneItemAndItsChildren(string $code): ServicePackageVersion
    {
        $documentProcessing = ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING);
        $graveDigging = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);

        $package = (new DefineServicePackage)(
            code: $code,
            name: 'Paket Uji Anak Item',
            items: [[
                'service_definition_id' => $documentProcessing->id,
                'item_type' => ServicePackageItemType::INCLUDED,
                'quantity' => 1,
                'unit' => 'paket',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
                'evidence_required' => true,
                'evidence_requirements' => [
                    ['description' => 'Salinan surat keterangan kematian terunggah.', 'is_required' => true],
                ],
                'substitution_policies' => [
                    [
                        'substitute_service_definition_id' => $graveDigging->id,
                        'requires_customer_approval' => true,
                        'reason' => 'Uji substitusi.',
                    ],
                ],
            ]],
            actorReference: 9,
        );

        return (new PublishServicePackageVersion)($package->draftVersion(), actorReference: 9);
    }

    /**
     * An open draft version belonging to a DIFFERENT package — the
     * destination a re-pointing attack would move a frozen item to.
     */
    private function draftVersionOfAnotherPackage(): ServicePackageVersion
    {
        $documentProcessing = ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING);

        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_TUJUAN_DRAFT',
            name: 'Paket Uji Tujuan Draft',
            items: [[
                'service_definition_id' => $documentProcessing->id,
                'item_type' => ServicePackageItemType::INCLUDED,
                'quantity' => 1,
                'unit' => 'paket',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
            ]],
            actorReference: 9,
        );

        return $package->draftVersion();
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

    // ------------------------------------------------------------------
    // The five AC2 bypasses closed by the 09 Aug 2026 ServiceCatalog
    // Superpowers retrofit (Ruling C, C-1..C-5). Every one of them is
    // reachable in one or two lines of ORDINARY Eloquent — none needs the
    // query builder, so none was covered by the module's own disclosed
    // "a raw DB::table() update bypasses this" limitation.
    // ------------------------------------------------------------------

    /**
     * C-1 / F1. `deleting()` used to read the LIVE `$version->status` while
     * `saving()` correctly read `getOriginal('status')`, so downgrading the
     * status in memory first walked a still-published row straight past the
     * guard.
     */
    public function test_an_in_memory_status_downgrade_cannot_smuggle_a_published_version_past_the_delete_guard(): void
    {
        $version = $this->publishedVersionWithOneItem();

        $version->status = ServicePackageVersionStatus::DRAFT;

        try {
            $version->delete();
            $this->fail('Deleting a published version after an in-memory status downgrade should have thrown.');
        } catch (PublishedServicePackageVersionIsImmutableException) {
            // expected
        }

        $this->assertDatabaseCount('service_package_versions', 1);
        $this->assertDatabaseHas('service_package_versions', [
            'id' => $version->id,
            'status' => ServicePackageVersionStatus::PUBLISHED,
        ]);
    }

    /**
     * C-3 / F3. The immutability half of `saving()` was gated on
     * `$version->exists`, so it never fired on INSERT — and `status` is
     * fillable. A direct `create([... 'status' => 'published'])` therefore
     * minted a frozen, ZERO-ITEM version, defeating
     * `PublishServicePackageVersion`'s zero-item refusal.
     */
    public function test_a_version_cannot_be_inserted_directly_as_published(): void
    {
        // The legal path still works — this fixture publishes a draft
        // through the real Action, which is the non-regression control for
        // the insert-time guard added below.
        $published = $this->publishedVersionWithOneItem();
        $this->assertTrue($published->isPublished());

        try {
            ServicePackageVersion::create([
                'service_package_id' => $published->service_package_id,
                'version_number' => 9,
                'status' => ServicePackageVersionStatus::PUBLISHED,
            ]);
            $this->fail('Creating a service_package_versions row directly as published should have thrown.');
        } catch (PublishedServicePackageVersionIsImmutableException) {
            // expected
        }

        $this->assertDatabaseMissing('service_package_versions', ['version_number' => 9]);
        $this->assertDatabaseCount('service_package_versions', 1);
    }

    /**
     * C-2 / F2. `assertOwningVersionIsEditable()` checked only the INCOMING
     * version id, so moving an item INTO a published version was blocked
     * while moving one OUT of a published version — the destructive
     * direction — was permitted, on save and on delete alike.
     */
    public function test_an_item_cannot_be_re_pointed_off_a_published_version_by_save_or_by_delete(): void
    {
        $published = $this->publishedVersionWithOneItem();
        $frozenItemId = $published->items()->sole()->id;
        $draftVersionId = $this->draftVersionOfAnotherPackage()->id;

        $item = ServicePackageItem::query()->findOrFail($frozenItemId);
        $item->service_package_version_id = $draftVersionId;

        try {
            $item->save();
            $this->fail('Re-pointing a published version\'s item onto a draft version should have thrown.');
        } catch (PublishedServicePackageVersionIsImmutableException) {
            // expected
        }

        $this->assertDatabaseHas('service_package_items', [
            'id' => $frozenItemId,
            'service_package_version_id' => $published->id,
        ]);

        $itemForDelete = ServicePackageItem::query()->findOrFail($frozenItemId);
        $itemForDelete->service_package_version_id = $draftVersionId;

        try {
            $itemForDelete->delete();
            $this->fail('Deleting a published version\'s item after an in-memory re-point should have thrown.');
        } catch (PublishedServicePackageVersionIsImmutableException) {
            // expected
        }

        $this->assertDatabaseHas('service_package_items', [
            'id' => $frozenItemId,
            'service_package_version_id' => $published->id,
        ]);
    }

    /**
     * C-4a / F4a. `ServicePackage` had no `booted()` at all, so
     * `$package->delete()` fired no guard and the FK's `cascadeOnDelete()`
     * then destroyed every version — published ones included — plus their
     * items, substitution policies and evidence requirements, without a
     * single Eloquent event firing anywhere in the chain.
     */
    public function test_a_package_that_owns_a_published_version_cannot_be_deleted(): void
    {
        $version = $this->publishedVersionWithOneItem();
        $package = ServicePackage::query()->findOrFail($version->service_package_id);

        try {
            $package->delete();
            $this->fail('Deleting a package that owns a published version should have thrown.');
        } catch (PublishedServicePackageVersionIsImmutableException) {
            // expected
        }

        $this->assertDatabaseCount('service_packages', 1);
        $this->assertDatabaseCount('service_package_versions', 1);
        $this->assertDatabaseCount('service_package_items', 1);
    }

    /**
     * The positive control for the guard above: the migration's own
     * cascade reasoning ("a version has no independent existence once its
     * owning package is gone") still holds for a package that has never
     * been published, and the guard must not have broken it.
     */
    public function test_a_package_with_only_a_draft_version_can_still_be_deleted(): void
    {
        $documentProcessing = ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING);

        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_HAPUS_DRAFT',
            name: 'Paket Uji Hapus Draft',
            items: [[
                'service_definition_id' => $documentProcessing->id,
                'item_type' => ServicePackageItemType::INCLUDED,
                'quantity' => 1,
                'unit' => 'paket',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
            ]],
            actorReference: 9,
        );

        $package->delete();

        $this->assertDatabaseCount('service_packages', 0);
        $this->assertDatabaseCount('service_package_versions', 0);
        $this->assertDatabaseCount('service_package_items', 0);
    }

    /**
     * C-5 / F5. `SubstitutionPolicy` and `EvidenceRequirement` defined no
     * `booted()` at all, so a published version's substitution rule could be
     * flipped and its evidence requirement deleted through ordinary
     * Eloquent, with no exception — AC2's guard existed at the version and
     * item levels and was simply absent at the third.
     */
    public function test_the_per_item_children_of_a_published_version_cannot_be_changed_added_or_removed(): void
    {
        $version = $this->publishedVersionWithOneItemAndItsChildren('PAKET_UJI_ANAK_TERBIT');
        $item = $version->items()->sole();

        $policy = $item->substitutionPolicies()->sole();
        $requirement = $item->evidenceRequirements()->sole();
        $graveDigging = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);

        $attempts = [
            'update a substitution policy' => fn () => $policy->forceFill(['requires_customer_approval' => false])->save(),
            'delete a substitution policy' => fn () => $policy->delete(),
            'update an evidence requirement' => fn () => $requirement->forceFill(['is_required' => false])->save(),
            'delete an evidence requirement' => fn () => $requirement->delete(),
            'add a new evidence requirement' => fn () => EvidenceRequirement::create([
                'service_package_item_id' => $item->id,
                'description' => 'Bukti tambahan yang tidak pernah disetujui.',
                'is_required' => true,
            ]),
            'add a new substitution policy' => fn () => SubstitutionPolicy::create([
                'service_package_item_id' => $item->id,
                'substitute_service_definition_id' => $graveDigging->id,
                'requires_customer_approval' => false,
            ]),
        ];

        foreach ($attempts as $description => $attempt) {
            try {
                $attempt();
                $this->fail("Attempting to {$description} on a published version should have thrown.");
            } catch (PublishedServicePackageVersionIsImmutableException) {
                // expected
            }
        }

        $this->assertTrue((bool) $policy->fresh()->requires_customer_approval);
        $this->assertTrue((bool) $requirement->fresh()->is_required);
        $this->assertSame(1, $item->substitutionPolicies()->count());
        $this->assertSame(1, $item->evidenceRequirements()->count());
    }

    /**
     * The draft-side positive control for the guard above — all four
     * operations must still succeed while the owning version is a draft,
     * which is the whole authoring workflow.
     */
    public function test_the_per_item_children_of_a_draft_version_can_still_be_changed_added_and_removed(): void
    {
        $documentProcessing = ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING);
        $graveDigging = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);

        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_ANAK_DRAFT',
            name: 'Paket Uji Anak Draft',
            items: [[
                'service_definition_id' => $documentProcessing->id,
                'item_type' => ServicePackageItemType::INCLUDED,
                'quantity' => 1,
                'unit' => 'paket',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
                'evidence_requirements' => [['description' => 'Bukti awal.']],
                'substitution_policies' => [[
                    'substitute_service_definition_id' => $graveDigging->id,
                    'requires_customer_approval' => true,
                ]],
            ]],
            actorReference: 9,
        );

        $item = $package->draftVersion()->items()->sole();

        $policy = $item->substitutionPolicies()->sole();
        $policy->forceFill(['requires_customer_approval' => false])->save();
        $this->assertFalse((bool) $policy->fresh()->requires_customer_approval);

        EvidenceRequirement::create([
            'service_package_item_id' => $item->id,
            'description' => 'Bukti tambahan.',
        ]);
        $this->assertSame(2, $item->evidenceRequirements()->count());

        $item->evidenceRequirements()->get()->each(fn (EvidenceRequirement $requirement) => $requirement->delete());
        $this->assertSame(0, $item->evidenceRequirements()->count());

        $policy->delete();
        $this->assertSame(0, $item->substitutionPolicies()->count());
    }
}
