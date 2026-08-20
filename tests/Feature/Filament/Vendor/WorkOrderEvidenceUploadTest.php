<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Vendor;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\VendorFulfillment\Models\WorkEvidence;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\WorkOrderStatus;
use App\Filament\Vendor\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Models\User;
use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The vendor panel's 'Unggah Bukti' header action on `ViewWorkOrder`
 * (`App\Filament\Vendor\Resources\WorkOrders\Actions\UploadEvidenceAction`)
 * — P5b's own plan named this exact gap (Task 4: "evidence upload via
 * vault"). Proves the same shape `CertificateAdminTest` already proves for
 * `CreateCertificateAction`: a real vault upload through the whole
 * quarantine → scan → promote pipeline, driven synchronously (this host has
 * no always-on media worker), then `UploadEvidence` records the row against
 * an ACCEPTED document — never a placeholder or a skipped scan.
 */
final class WorkOrderEvidenceUploadTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->storageRoot = sys_get_temp_dir().'/work-evidence-vault-'.Str::random(12);
        mkdir($this->storageRoot, 0700, true);
        $this->app->instance(ObjectStorage::class, new LocalFilesystemObjectStorage($this->storageRoot));

        Filament::setCurrentPanel('vendor');
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);
        $this->removeDirectory($this->storageRoot);

        parent::tearDown();
    }

    /**
     * @return array{User, WorkOrder}
     */
    private function workOrderForGrantedVendor(): array
    {
        $vendor = Vendor::query()->create(['name' => 'Vendor Uji', 'is_active' => true]);

        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Perawatan Bulanan Standar',
            'product_code' => 'GRAVE_CARE_MONTHLY',
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'checklist_template' => ['membersihkan makam'],
            'status' => 'active',
        ]);

        $workOrder = WorkOrder::query()->create([
            'reference' => 'WO-'.Str::upper(Str::random(8)),
            'care_plan_id' => $carePlan->getKey(),
            'vendor_id' => $vendor->getKey(),
            'status' => WorkOrderStatus::InProgress->value,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => (string) $vendor->id,
        ]);

        $this->actingAs($user);
        $this->app->forgetScopedInstances();
        $this->forgetResolvedActorContext();

        return [$user, $workOrder];
    }

    /**
     * A real 1x1 PNG, GD-free (the CI runner has no gd extension — same
     * fixture shape `MemorialPublicPageTest::minimalPng()` uses).
     */
    private function evidencePng(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'bukti.png',
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
                true,
            ),
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }

    public function test_a_vendor_uploads_evidence_through_the_resource_with_a_real_vault_upload(): void
    {
        [, $workOrder] = $this->workOrderForGrantedVendor();

        Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->callAction('unggahBukti', data: [
                'evidence_type' => 'before',
                'document_file' => $this->evidencePng(),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Bukti pekerjaan diunggah.');

        $evidence = WorkEvidence::query()->sole();
        $this->assertSame($workOrder->getKey(), $evidence->work_order_id);
        $this->assertSame('before', $evidence->evidence_type);

        $document = Document::query()->sole();
        $this->assertSame(DocumentState::Accepted, $document->state);
        $this->assertSame(DocumentKind::VendorEvidence, $document->document_kind);
        $this->assertSame((string) $document->getKey(), $evidence->document_id);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'EVIDENCE_UPLOADED',
            'subject_id' => (string) $workOrder->getKey(),
        ]);
    }

    public function test_evidence_upload_requires_an_evidence_type(): void
    {
        [, $workOrder] = $this->workOrderForGrantedVendor();

        Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->callAction('unggahBukti', data: [
                'document_file' => $this->evidencePng(),
            ])
            ->assertHasActionErrors(['evidence_type' => ['required']]);

        $this->assertSame(0, WorkEvidence::query()->count());
    }

    public function test_a_vendor_cannot_upload_evidence_against_another_vendors_work_order(): void
    {
        [, $workOrder] = $this->workOrderForGrantedVendor();

        // A second vendor, granted its own (different) scope.
        $otherVendor = Vendor::query()->create(['name' => 'Vendor Lain', 'is_active' => true]);
        $otherUser = User::factory()->create();
        $this->grantRoleTo($otherUser, ActorRole::VENDOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $otherUser->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => (string) $otherVendor->id,
        ]);
        $this->actingAs($otherUser);
        $this->app->forgetScopedInstances();
        $this->forgetResolvedActorContext();

        // `ScopesToCurrentVendor` refuses to resolve the record at all —
        // mounting the page 404s, matching `ViewWorkOrder` (vendor)'s own
        // scoping guarantee (`VendorPanelScopingTest`'s established shape).
        $this->expectException(ModelNotFoundException::class);

        Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->getRouteKey()]);
    }
}
