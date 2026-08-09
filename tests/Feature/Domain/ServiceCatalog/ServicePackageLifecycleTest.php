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
 * fulfillment owner, service area, schedule window, and evidence are all
 * defined correctly), AC7 (items fulfilled by cemetery/platform/vendor), and
 * the
 * version-forward lifecycle `design.md` describes ("Later changes create
 * new versions, never mutate accepted quote contents"), end to end through
 * the real Actions — never a raw `Model::create()` call, mirroring
 * `tests/Feature/Domain/Faq/FaqArticleDraftExclusionTest.php`'s own
 * "exercise the real write Actions" convention. AC2 immutability itself has
 * its own dedicated, deeper test:
 * `ServicePackageVersionImmutabilityTest.php`.
 *
 * The AC1 "service area" half of that claim was UNEARNED until 09 Aug 2026:
 * `standardItems()` supplied no `service_area` and no test asserted one,
 * while this block said it was proven. `standardItems()` now carries a real
 * value and both the define and the revise tests assert it round-trips
 * (ServiceCatalog Superpowers retrofit, F21).
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
                // AC1's "service area" — supplied on exactly one item so the
                // null default on the others stays exercised too.
                'service_area' => 'DKI Jakarta',
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

        // AC1's "service area" — supplied by `standardItems()` on the
        // grave-digging item only, `null` everywhere else.
        $graveDiggingItem = $version->items()
            ->where('service_definition_id', ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING)->id)
            ->sole();
        $this->assertSame('DKI Jakarta', $graveDiggingItem->service_area);
        $this->assertTrue((bool) $graveDiggingItem->requires_schedule_window);
        $this->assertNull($documentItem->service_area);
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

    /**
     * F19 (09 Aug 2026 retrofit). The throw was already asserted; the
     * ROLLBACK was not. `DefineServicePackage` creates the package and its
     * version at `:86-101` and only then resolves each item's
     * `service_definition_id` with `findOrFail` at `:156`, so without the
     * `DB::transaction` wrapper this leaves an orphan package plus an empty
     * version behind — and the suite stayed green either way.
     */
    public function test_defining_an_item_with_an_unknown_service_definition_id_is_rejected_and_rolls_the_whole_package_back(): void
    {
        try {
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

            $this->fail('Defining a package with an unknown service_definition_id should have thrown.');
        } catch (ModelNotFoundException) {
            // expected
        }

        $this->assertDatabaseCount('service_packages', 0);
        $this->assertDatabaseCount('service_package_versions', 0);
        $this->assertDatabaseCount('service_package_items', 0);
    }

    /**
     * F17 (09 Aug 2026 retrofit). `PublishServicePackageVersion.php:60-64`'s
     * zero-item refusal had ZERO coverage anywhere in the module — deleting
     * that guard left the whole suite green. The test whose NAME claimed to
     * cover it (below, now renamed) never attempted an empty publish.
     *
     * A zero-item draft is not directly constructible — `DefineServicePackage`
     * requires at least one item — so this builds one and then deletes it,
     * which is legal precisely because the version is still a draft.
     */
    public function test_publishing_a_version_with_zero_items_is_rejected(): void
    {
        $package = (new DefineServicePackage)(
            code: 'PAKET_UJI_TANPA_ITEM',
            name: 'Paket Uji Tanpa Item',
            items: [[
                'service_definition_id' => ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING)->id,
                'item_type' => ServicePackageItemType::INCLUDED,
                'quantity' => 1,
                'unit' => 'paket',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
            ]],
            actorReference: 7,
        );

        $draft = $package->draftVersion();
        $draft->items()->sole()->delete();
        $this->assertSame(0, $draft->items()->count());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has zero items and cannot be published');

        (new PublishServicePackageVersion)($draft, actorReference: 7);
    }

    /**
     * Renamed 09 Aug 2026 (F17): the old name
     * `test_publishing_freezes_the_version_and_cannot_publish_an_empty_or_already_published_one_twice`
     * named three guarantees and proved one. The empty-publish half now has
     * its own test above; this one claims only what its body proves.
     */
    public function test_publishing_freezes_the_version_and_republishing_it_is_idempotent(): void
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

        // F18 (09 Aug 2026 retrofit). This used to be
        // `assertSame($v1->items()->count(), $v2->items()->count())` — two
        // DERIVED values compared against each other, which a `copyItem()`
        // that dropped or corrupted every attribute would still satisfy.
        // Absolute count, then every attribute AC1 names, per item.
        $this->assertSame(4, $v2->items()->count());

        $sourceItems = $v1->items()->orderBy('id')->get()->keyBy('service_definition_id');
        $copiedItems = $v2->items()->orderBy('id')->get()->keyBy('service_definition_id');

        $this->assertSame($sourceItems->keys()->all(), $copiedItems->keys()->all());

        foreach ($sourceItems as $serviceDefinitionId => $source) {
            $copy = $copiedItems->get($serviceDefinitionId);
            $this->assertNotNull($copy, "Item for service definition [{$serviceDefinitionId}] was not copied.");

            $this->assertSame($source->item_type, $copy->item_type);
            $this->assertSame($source->quantity, $copy->quantity);
            $this->assertSame($source->unit, $copy->unit);
            $this->assertSame($source->fulfillment_owner, $copy->fulfillment_owner);
            $this->assertSame($source->service_area, $copy->service_area);
            $this->assertSame((bool) $source->requires_schedule_window, (bool) $copy->requires_schedule_window);
            $this->assertSame((bool) $source->evidence_required, (bool) $copy->evidence_required);
            $this->assertSame($source->notes, $copy->notes);

            // The copy is a genuinely new row, not the same one relinked.
            $this->assertNotSame($source->id, $copy->id);
            $this->assertSame($v2->id, $copy->service_package_version_id);
        }

        // Evidence requirements were asserted by NOTHING before this — a
        // `copyItem()` that dropped every one of them kept the old test
        // green (`ReviseServicePackageVersion.php:111-117`).
        $sourceDocumentItem = $sourceItems->get(ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING)->id);
        $copiedDocumentItem = $copiedItems->get(ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING)->id);

        $this->assertSame(1, $copiedDocumentItem->evidenceRequirements()->count());
        $sourceEvidence = $sourceDocumentItem->evidenceRequirements()->sole();
        $copiedEvidence = $copiedDocumentItem->evidenceRequirements()->sole();
        $this->assertSame($sourceEvidence->description, $copiedEvidence->description);
        $this->assertSame((bool) $sourceEvidence->is_required, (bool) $copiedEvidence->is_required);
        $this->assertNotSame($sourceEvidence->id, $copiedEvidence->id);

        // Substitution policies, likewise by absolute count and by value.
        $sourceHearseItem = $sourceItems->get(ServiceDefinition::findByCode(ServiceCode::HEARSE)->id);
        $copiedHearseItem = $copiedItems->get(ServiceDefinition::findByCode(ServiceCode::HEARSE)->id);

        $this->assertSame(1, $copiedHearseItem->substitutionPolicies()->count());
        $sourcePolicy = $sourceHearseItem->substitutionPolicies()->sole();
        $copiedPolicy = $copiedHearseItem->substitutionPolicies()->sole();
        $this->assertSame($sourcePolicy->substitute_service_definition_id, $copiedPolicy->substitute_service_definition_id);
        $this->assertSame((bool) $sourcePolicy->requires_customer_approval, (bool) $copiedPolicy->requires_customer_approval);
        $this->assertSame($sourcePolicy->reason, $copiedPolicy->reason);
        $this->assertNotSame($sourcePolicy->id, $copiedPolicy->id);

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

    /**
     * F16 (09 Aug 2026 retrofit) — this test used to PASS ON THE WRONG
     * BRANCH. `DefineServicePackage` always leaves a version-1 draft, so
     * `ReviseServicePackageVersion.php:57`'s open-draft guard threw first and
     * the never-published branch at `:65-69` was reached by no test in the
     * module; the bare `expectException(InvalidArgumentException::class)`
     * could not tell the two apart, and the very next test asserts the
     * open-draft branch on purpose. Deleting the never-published guard left
     * the suite green.
     *
     * Discarding the draft first is what actually reaches the branch, and the
     * message assertion is what keeps the two apart from here on.
     */
    public function test_revise_is_rejected_when_the_package_has_never_been_published(): void
    {
        $package = (new DefineServicePackage)(
            code: 'PAKET_BELUM_TERBIT',
            name: 'Paket Belum Terbit',
            items: $this->standardItems(),
            actorReference: 7,
        );

        // Legal: only PUBLISHED versions are protected from deletion.
        $package->draftVersion()->delete();
        $this->assertNull($package->draftVersion());
        $this->assertNull($package->currentPublishedVersion());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has never been published; nothing to revise');

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
        // The message assertion is not decoration: it is the only thing
        // separating this branch from the never-published one above, which
        // throws the same exception class (F16).
        $this->expectExceptionMessage('already has an open draft version');

        (new ReviseServicePackageVersion)($package, actorReference: 7);
    }
}
