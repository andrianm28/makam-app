<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\VendorFulfillment;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\VendorFulfillment\Actions\CreateWorkOrderFromCycle;
use App\Domain\VendorFulfillment\Actions\UploadEvidence;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests for `UploadEvidence` — vault upload creates work_evidence,
 * rejected before acceptance.
 */
final class EvidenceUploadTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkOrder(): WorkOrder
    {
        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Basic Care',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'product_code' => 'GC-MONTHLY',
            'status' => 'active',
            'checklist_template' => [],
        ]);
        $subscription = Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => (string) Str::uuid(),
            'status' => 'active',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'currency' => 'IDR',
        ]);
        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth(),
            'cycle_end' => now(),
            'status' => 'PAID',
        ]);

        return app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);
    }

    private function makeAcceptedDocument(): Document
    {
        return Document::createQuarantined([
            'document_kind' => DocumentKind::VendorEvidence->value,
            'owner_type' => 'work_order',
            'owner_id' => 'test-work-order',
            'original_filename' => 'evidence.jpg',
            'storage_prefix' => 'test',
            'storage_key' => Str::random(32),
            'size_bytes' => 1024,
            'mime_declared' => 'image/jpeg',
            'checksum_sha256' => Str::random(64),
        ]);
    }

    private function promoteDocumentToAccepted(Document $document): Document
    {
        $document->state = DocumentState::Scanning;
        $document->save();
        $document->promote();

        return $document->fresh();
    }

    public function test_upload_creates_work_evidence_for_accepted_document(): void
    {
        $workOrder = $this->makeWorkOrder();
        $document = $this->promoteDocumentToAccepted($this->makeAcceptedDocument());

        $evidence = app(UploadEvidence::class)(
            $workOrder,
            (string) $document->getKey(),
            'after',
            (string) Str::uuid(),
        );

        $this->assertSame((string) $workOrder->getKey(), $evidence->work_order_id);
        $this->assertSame((string) $document->getKey(), $evidence->document_id);
        $this->assertSame('after', $evidence->evidence_type);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'EVIDENCE_UPLOADED',
            'subject_type' => 'work_order',
            'subject_id' => (string) $workOrder->getKey(),
        ]);
    }

    public function test_upload_rejects_non_accepted_document(): void
    {
        $workOrder = $this->makeWorkOrder();
        $document = $this->makeAcceptedDocument();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('evidence requires an ACCEPTED document');

        app(UploadEvidence::class)(
            $workOrder,
            (string) $document->getKey(),
            'before',
            (string) Str::uuid(),
        );
    }

    public function test_upload_rejects_invalid_evidence_type(): void
    {
        $workOrder = $this->makeWorkOrder();
        $document = $this->promoteDocumentToAccepted($this->makeAcceptedDocument());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("evidence_type must be 'before' or 'after'");

        app(UploadEvidence::class)(
            $workOrder,
            (string) $document->getKey(),
            'invalid',
            (string) Str::uuid(),
        );
    }

    public function test_evidence_types_are_before_and_after(): void
    {
        $workOrder = $this->makeWorkOrder();
        $doc1 = $this->promoteDocumentToAccepted($this->makeAcceptedDocument());
        $doc2 = $this->promoteDocumentToAccepted($this->makeAcceptedDocument());

        $before = app(UploadEvidence::class)(
            $workOrder,
            (string) $doc1->getKey(),
            'before',
            (string) Str::uuid(),
        );

        $this->assertSame('before', $before->evidence_type);

        $after = app(UploadEvidence::class)(
            $workOrder,
            (string) $doc2->getKey(),
            'after',
            (string) Str::uuid(),
        );

        $this->assertSame('after', $after->evidence_type);
    }
}
