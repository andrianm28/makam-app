<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use App\Models\User;
use App\Platform\Audit\AuditOutcome;
use App\Platform\DocumentVault\Actions\IssueSignedUrl;
use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\Contracts\StoragePathResolver;
use App\Platform\DocumentVault\DocumentAccessPurpose;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\DocumentAccessEvent;
use App\Platform\DocumentVault\Models\SignedUrlGrant;
use App\Platform\DocumentVault\StoragePathPolicy;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class DownloadDocumentTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/document-vault-download-'.Str::random(12);
        $this->app->instance(ObjectStorage::class, new LocalFilesystemObjectStorage($this->root));
        $this->app->instance(StoragePathResolver::class, new StoragePathPolicy);
        $this->app->instance(ActorContext::class, $this->actor());
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_valid_download_grant_streams_the_accepted_attachment_and_consumes_once(): void
    {
        $document = $this->documentWithAcceptedBytes();
        $grant = $this->issue($document);

        $response = $this->get($this->downloadUrl($document, $grant));

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename=akta-kematian.pdf');
        $this->assertSame($this->bytes(), $response->streamedContent());
        $this->assertNotNull($grant->fresh()->consumed_at);
        $this->assertSame(1, DocumentAccessEvent::query()->where('outcome', AuditOutcome::Allowed->value)->where('purpose', 'DOWNLOAD')->count());
    }

    public function test_consumed_expired_and_foreign_tokens_are_uniform_404_denials(): void
    {
        $document = $this->documentWithAcceptedBytes();

        $consumed = $this->issue($document);
        $consumed->consume();
        $this->get($this->downloadUrl($document, $consumed))->assertNotFound();

        $expired = $this->issue($document);
        CarbonImmutable::setTestNow($expired->expires_at->addSecond());
        $this->get($this->downloadUrl($document, $expired))->assertNotFound();
        CarbonImmutable::setTestNow();

        ScopeAssignment::query()->create([
            'actor_identifier' => '99',
            'entity_type' => ScopeEntityType::ORDER,
            'entity_id' => $document->owner_id,
        ]);
        $foreign = $this->issue($document, new ActorContext(99, ['operator']));
        $this->app->instance(ActorContext::class, $this->actor());
        $this->get($this->downloadUrl($document, $foreign))->assertNotFound();
    }

    public function test_cross_purpose_revoked_policy_actor_binding_and_nonaccepted_denials_are_404(): void
    {
        $document = $this->documentWithAcceptedBytes();
        $viewGrant = $this->issue($document, purpose: DocumentAccessPurpose::View);
        $this->get($this->downloadUrl($document, $viewGrant))->assertNotFound();

        $revoked = $this->issue($document);
        ScopeAssignment::query()->delete();
        $this->get($this->downloadUrl($document, $revoked))->assertNotFound();

        $unaccepted = $this->documentWithAcceptedBytes();
        $unacceptedGrant = $this->issue($unaccepted);
        DB::table('documents')->where('id', $unaccepted->id)->update(['state' => DocumentState::Scanning->value, 'storage_prefix' => 'quarantine']);
        $this->get($this->downloadUrl($unaccepted, $unacceptedGrant))->assertNotFound();
    }

    public function test_served_and_refused_redemptions_record_access_rows(): void
    {
        $document = $this->documentWithAcceptedBytes();
        $grant = $this->issue($document);
        $this->get($this->downloadUrl($document, $grant))->assertOk();
        $response = $this->get($this->downloadUrl($document, $grant));
        $response->assertNotFound();

        $events = DocumentAccessEvent::query()->where('document_id', $document->id)->get();
        $this->assertSame(3, $events->count());
        $downloadEvents = $events->where('purpose', DocumentAccessPurpose::Download);
        $this->assertSame(2, $downloadEvents->count());
        $this->assertSame(1, $downloadEvents->where('outcome', AuditOutcome::Allowed->value)->count());
        $this->assertSame(1, $downloadEvents->where('outcome', AuditOutcome::Denied->value)->count());
        $this->assertStringNotContainsString('/storage/', $this->app->make(IssueSignedUrl::class)->temporaryUrl($grant));
        $this->assertStringNotContainsString('quarantine', $this->app->make(IssueSignedUrl::class)->temporaryUrl($grant));
    }

    public function test_an_unexpected_provider_read_failure_returns_404_records_failed_access_and_exposes_no_bytes(): void
    {
        $document = $this->documentWithAcceptedBytes();
        $grant = $this->issue($document);
        $this->app->instance(
            ObjectStorage::class,
            new ThrowingReadObjectStorage(new LocalFilesystemObjectStorage($this->root)),
        );

        $response = $this->get($this->downloadUrl($document, $grant));
        $response->assertNotFound();

        $failed = DocumentAccessEvent::query()
            ->where('document_id', $document->id)
            ->where('outcome', AuditOutcome::Failed->value)
            ->sole();

        $this->assertSame(DocumentAccessPurpose::Download, $failed->purpose);
        $this->assertNull($grant->fresh()->consumed_at);
        $this->assertStringNotContainsString($this->bytes(), (string) $response->getContent());
    }

    public function test_a_quarantined_object_cannot_be_served_by_the_local_public_url_contract(): void
    {
        $path = 'documents/'.DocumentKind::DeathCertificate->value.'/quarantine/'.Str::random(16).'.pdf';
        Storage::disk('local')->put($path, $this->bytes());

        try {
            $publicUrl = Storage::disk('local')->url($path);

            $this->get($publicUrl)
                ->assertNotFound()
                ->assertDontSee($this->bytes(), escape: false);
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    private function issue(Document $document, ?ActorContext $actor = null, DocumentAccessPurpose $purpose = DocumentAccessPurpose::Download): SignedUrlGrant
    {
        return $this->app->make(IssueSignedUrl::class)->issue($actor ?? $this->actor(), $document, $purpose);
    }

    private function actor(): ActorContext
    {
        return new ActorContext(42, ['operator']);
    }

    private function documentWithAcceptedBytes(): Document
    {
        $id = (string) Str::uuid();
        $ownerId = 'order-'.Str::random(8);
        $key = 'opaque-'.Str::random(8);
        DB::table('documents')->insert([
            'id' => $id, 'document_kind' => DocumentKind::DeathCertificate->value,
            'state' => DocumentState::Accepted->value, 'owner_type' => ScopeEntityType::ORDER,
            'owner_id' => $ownerId, 'original_filename' => 'akta-kematian.pdf',
            'storage_prefix' => 'accepted', 'storage_key' => $key, 'size_bytes' => strlen($this->bytes()),
            'mime_declared' => 'application/pdf', 'scanner_required' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        ScopeAssignment::query()->create(['actor_identifier' => '42', 'entity_type' => ScopeEntityType::ORDER, 'entity_id' => $ownerId]);
        $document = Document::query()->findOrFail($id);
        $storage = $this->app->make(ObjectStorage::class);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->bytes());
        rewind($stream);
        $storage->put($document->document_kind->value.'/quarantine/'.$key, $stream);
        fclose($stream);
        $storage->copy($document->document_kind->value.'/quarantine/'.$key, $document->document_kind->value.'/accepted/'.$key);

        return $document;
    }

    private function downloadUrl(Document $document, SignedUrlGrant $grant): string
    {
        return "/internal/documents/{$document->id}/download/{$grant->token}";
    }

    private function bytes(): string
    {
        return "%PDF-1.4 private document\n";
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}

final class ThrowingReadObjectStorage implements ObjectStorage
{
    public function __construct(private readonly LocalFilesystemObjectStorage $delegate) {}

    public function put(string $path, $stream): void
    {
        $this->delegate->put($path, $stream);
    }

    public function copy(string $sourcePath, string $destinationPath): void
    {
        $this->delegate->copy($sourcePath, $destinationPath);
    }

    public function read(string $path)
    {
        throw new RuntimeException('simulated provider read failure');
    }

    public function checksum(string $path): string
    {
        return $this->delegate->checksum($path);
    }

    public function delete(string $path): void
    {
        $this->delegate->delete($path);
    }

    public function deleteIfExists(string $path): void
    {
        $this->delegate->deleteIfExists($path);
    }

    public function deleteAcceptedIfExists(string $path): void
    {
        $this->delegate->deleteAcceptedIfExists($path);
    }
}
