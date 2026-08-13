<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\OrderWorkflow\Actions\AttachOrderDocument;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderDocument;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\DocumentVault\Actions\IssueSignedUrl;
use App\Platform\DocumentVault\Actions\PromoteDocument;
use App\Platform\DocumentVault\Actions\ScanDocument;
use App\Platform\DocumentVault\Actions\UploadDocument;
use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\Adapters\MockScanner;
use App\Platform\DocumentVault\DocumentAccessPurpose;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\DocumentValidator;
use App\Platform\DocumentVault\Exceptions\DocumentAccessDeniedException;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\DocumentAccessEvent;
use App\Platform\DocumentVault\Models\SignedUrlGrant;
use App\Platform\DocumentVault\Policies\DocumentAccessPolicy;
use App\Platform\DocumentVault\StoragePathPolicy;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 5 (AC10): order documents are vault documents, purpose-scoped to the
 * order. `AttachOrderDocument` composes `UploadDocument::upload()` with
 * `ownerType = 'order'` — it never reimplements storage, quarantine, scan,
 * promotion, signed URLs, or audit. These tests exercise the composed whole
 * so a regression in either side (the Action or the vault) shows up here.
 */
final class OrderDocumentTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private LocalFilesystemObjectStorage $storage;

    private StoragePathPolicy $paths;

    private AttachOrderDocument $attach;

    private IssueSignedUrl $signedUrls;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/order-document-vault-'.Str::random(12);
        $this->storage = new LocalFilesystemObjectStorage($this->root);
        $this->paths = new StoragePathPolicy;
        $this->attach = new AttachOrderDocument(new UploadDocument(
            $this->storage,
            $this->paths,
            new DocumentValidator,
        ));
        $this->signedUrls = new IssueSignedUrl(new DocumentAccessPolicy(new ScopeAssignmentResolver(ActorContext::guest())));

        config(['document-vault.scanner_outage' => false]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_a_quarantined_order_document_is_never_previewable_downloadable_or_thumbnailed(): void
    {
        $order = $this->order();
        $bound = $this->attach->__invoke(
            $order,
            DocumentKind::DeathCertificate,
            $this->uploadedFile($this->minimalPdf(), 'akta-kematian.pdf', 'application/pdf'),
            'client-upload-1',
            [],
            '42',
            'operator',
        );

        $document = Document::query()->findOrFail($bound->document_id);
        $this->assertSame(DocumentState::Quarantined, $document->state);

        $actor = new ActorContext(identityReference: 42, roles: ['operator']);
        $this->grantOrderScope('42', $order->getKey());

        foreach ([DocumentAccessPurpose::View, DocumentAccessPurpose::Download] as $purpose) {
            try {
                $this->signedUrls->issue($actor, $document, $purpose);
                $this->fail("Quarantined document must not yield a {$purpose->value} signed URL.");
            } catch (DocumentAccessDeniedException) {
                // expected
            }
        }

        $this->assertSame(0, SignedUrlGrant::query()->count());
        $this->assertSame(2, DocumentAccessEvent::query()->where('outcome', AuditOutcome::Denied->value)->count());
        $this->assertSame(2, AuditEvent::query()->where('action', 'DOCUMENT_ACCESS_DENIED')->count());
    }

    public function test_a_signed_url_for_an_accepted_order_document_expires_within_three_hundred_seconds(): void
    {
        $order = $this->order();
        $bound = $this->attach->__invoke(
            $order,
            DocumentKind::DeathCertificate,
            $this->uploadedFile($this->minimalPdf(), 'akta-kematian.pdf', 'application/pdf'),
            'client-upload-2',
            [],
            '42',
            'operator',
        );

        $document = Document::query()->findOrFail($bound->document_id);
        $document = $this->accept($document);
        $this->grantOrderScope('42', $order->getKey());

        $grant = $this->signedUrls->issue(
            new ActorContext(identityReference: 42, roles: ['operator']),
            $document,
            DocumentAccessPurpose::Download,
        );

        $this->assertSame(300, IssueSignedUrl::MAX_TTL_SECONDS);
        $this->assertSame(300, (int) $grant->created_at->diffInSeconds($grant->expires_at));
        $this->assertSame($order->getKey(), $document->owner_id);
    }

    public function test_every_access_to_an_order_document_writes_an_audit_row(): void
    {
        $order = $this->order();
        $bound = $this->attach->__invoke(
            $order,
            DocumentKind::DeathCertificate,
            $this->uploadedFile($this->minimalPdf(), 'akta-kematian.pdf', 'application/pdf'),
            'client-upload-3',
            [],
            '42',
            'operator',
        );

        $document = Document::query()->findOrFail($bound->document_id);
        $document = $this->accept($document);
        $actor = new ActorContext(identityReference: 42, roles: ['operator']);
        $this->grantOrderScope('42', $order->getKey());

        $this->signedUrls->issue($actor, $document, DocumentAccessPurpose::Download);

        $audit = AuditEvent::query()->where('action', 'DOCUMENT_ACCESS_GRANT')->sole();
        $this->assertSame(AuditOutcome::Allowed->value, $audit->outcome);
        $this->assertSame($document->getKey(), $audit->subject_id);
        $this->assertSame(['purpose' => DocumentAccessPurpose::Download->value], $audit->metadata);

        $access = DocumentAccessEvent::query()->sole();
        $this->assertSame($document->getKey(), $access->document_id);
        $this->assertSame(DocumentAccessPurpose::Grant, $access->purpose);
        $this->assertSame(AuditOutcome::Allowed->value, $access->outcome);

        try {
            $this->signedUrls->issue(ActorContext::guest(), $document, DocumentAccessPurpose::View);
            $this->fail('A guest must not receive a grant.');
        } catch (DocumentAccessDeniedException) {
            // expected
        }

        $this->assertSame(1, AuditEvent::query()->where('action', 'DOCUMENT_ACCESS_DENIED')->count());
    }

    public function test_a_document_bound_to_order_a_is_not_reachable_from_order_b(): void
    {
        $orderA = $this->order();
        $bound = $this->attach->__invoke(
            $orderA,
            DocumentKind::DeathCertificate,
            $this->uploadedFile($this->minimalPdf(), 'akta-kematian.pdf', 'application/pdf'),
            'client-upload-4',
            [],
            '42',
            'operator',
        );
        $document = Document::query()->findOrFail($bound->document_id);
        $document = $this->accept($document);

        $orderB = $this->order();
        $orderBActor = new ActorContext(identityReference: 99, roles: ['operator']);
        $this->grantOrderScope('99', $orderB->getKey());

        try {
            $this->signedUrls->issue($orderBActor, $document, DocumentAccessPurpose::Download);
            $this->fail('An actor scoped only to order B must not reach order A\'s document.');
        } catch (DocumentAccessDeniedException) {
            // expected
        }

        $this->assertSame(0, SignedUrlGrant::query()->count());

        $orderBDocuments = OrderDocument::query()
            ->where('order_id', $orderB->getKey())
            ->get();
        $this->assertCount(0, $orderBDocuments);

        $orderADocuments = OrderDocument::query()
            ->where('order_id', $orderA->getKey())
            ->get();
        $this->assertCount(1, $orderADocuments);
        $this->assertSame($bound->getKey(), $orderADocuments[0]->getKey());
    }

    public function test_re_attaching_the_same_document_to_the_same_order_is_idempotent(): void
    {
        $order = $this->order();

        $first = $this->attach->__invoke(
            $order,
            DocumentKind::DeathCertificate,
            $this->uploadedFile($this->minimalPdf(), 'akta-kematian.pdf', 'application/pdf'),
            'client-upload-5',
            [],
            '42',
            'operator',
        );

        $second = $this->attach->__invoke(
            $order,
            DocumentKind::DeathCertificate,
            $this->uploadedFile($this->minimalPdf(), 'akta-kematian.pdf', 'application/pdf'),
            'client-upload-5',
            [],
            '42',
            'operator',
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, OrderDocument::query()->count());
        $this->assertSame(1, Document::query()->count());
    }

    private function order(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-TEST-'.Str::random(6),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);
    }

    private function accept(Document $document): Document
    {
        (new ScanDocument($this->storage, $this->paths, new MockScanner))->scan($document);

        // `PromoteDocument::promote()` writes state on a freshly locked
        // instance, never the caller's — re-fetch so the returned document
        // reflects `ACCEPTED` before it is handed to `IssueSignedUrl`.
        return (new PromoteDocument($this->storage, $this->paths))->promote($document)->fresh();
    }

    private function grantOrderScope(string $actorRef, string $orderId): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => $actorRef,
            'entity_type' => ScopeEntityType::ORDER,
            'entity_id' => $orderId,
        ]);
    }

    private function uploadedFile(string $content, string $filename, string $mimeType): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'order-doc-vault-upload-');
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, $mimeType, null, true);
    }

    private function minimalPdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
