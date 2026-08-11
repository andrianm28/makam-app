<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use App\Platform\Audit\AuditOutcome;
use App\Platform\DocumentVault\Actions\RecordDocumentAccess;
use App\Platform\DocumentVault\DocumentAccessPurpose;
use App\Platform\DocumentVault\Exceptions\DocumentAccessEventIsImmutableException;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\DocumentAccessEvent;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AC8: every actual restricted access writes an append-only
 * `document_access_events` row carrying actor, purpose, record, timestamp and
 * outcome, plus a `document.accessed.v1` outbox event that carries only the
 * document reference, kind and state (Global Constraints: "Restricted data
 * never leaves the module").
 */
final class RecordDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_allowed_access_records_every_ac8_field(): void
    {
        $document = $this->acceptedDocument();

        $event = (new RecordDocumentAccess)->record(
            document: $document,
            actor: new ActorContext(identityReference: 42, roles: ['operator']),
            purpose: DocumentAccessPurpose::Download,
            outcome: AuditOutcome::Allowed,
            ipAddress: '192.0.2.10',
        );

        $this->assertSame($document->getKey(), $event->document_id);
        $this->assertSame('42', $event->actor_ref);
        $this->assertSame('operator', $event->actor_role);
        $this->assertSame(DocumentAccessPurpose::Download, $event->purpose);
        $this->assertSame(AuditOutcome::Allowed->value, $event->outcome);
        $this->assertSame('192.0.2.10', $event->ip_address);
        $this->assertNotNull($event->occurred_at);
    }

    public function test_a_guest_denial_still_records_a_non_null_actor_role(): void
    {
        $event = (new RecordDocumentAccess)->record(
            document: $this->acceptedDocument(),
            actor: ActorContext::guest(),
            purpose: DocumentAccessPurpose::View,
            outcome: AuditOutcome::Denied,
            ipAddress: null,
        );

        $this->assertNull($event->actor_ref);
        $this->assertSame('guest', $event->actor_role);
        $this->assertNull($event->ip_address);
        $this->assertSame(AuditOutcome::Denied->value, $event->outcome);
    }

    public function test_the_recorded_event_is_append_only(): void
    {
        $event = (new RecordDocumentAccess)->record(
            document: $this->acceptedDocument(),
            actor: new ActorContext(identityReference: 42, roles: ['admin']),
            purpose: DocumentAccessPurpose::View,
            outcome: AuditOutcome::Allowed,
        );

        $this->expectException(DocumentAccessEventIsImmutableException::class);

        $event->update(['outcome' => AuditOutcome::Denied->value]);
    }

    public function test_an_allowed_access_publishes_a_restricted_document_accessed_event(): void
    {
        $document = $this->acceptedDocument();

        $event = (new RecordDocumentAccess)->record(
            document: $document,
            actor: new ActorContext(identityReference: 42, roles: ['operator']),
            purpose: DocumentAccessPurpose::Download,
            outcome: AuditOutcome::Allowed,
        );

        $outbox = OutboxEvent::query()->where('event_name', 'document.accessed.v1')->sole();

        $this->assertSame(1, $outbox->event_version);
        $this->assertSame('document', $outbox->aggregate_type);
        $this->assertSame((string) $document->getKey(), $outbox->aggregate_id);
        $this->assertSame(OutboxClassification::Restricted->value, $outbox->classification);
        $this->assertSame("access:{$event->getKey()}", $outbox->idempotency_key);
    }

    public function test_the_outbox_payload_carries_only_the_document_reference_kind_and_state(): void
    {
        $document = $this->acceptedDocument();

        (new RecordDocumentAccess)->record(
            document: $document,
            actor: new ActorContext(identityReference: 42, roles: ['operator']),
            purpose: DocumentAccessPurpose::Download,
            outcome: AuditOutcome::Allowed,
        );

        $payload = OutboxEvent::query()->where('event_name', 'document.accessed.v1')->sole()->payload;

        $this->assertSame(['kind', 'state'], array_keys($payload));
        $this->assertSame('DEATH_CERTIFICATE', $payload['kind']);
        $this->assertSame('accepted', $payload['state']);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('akta-kematian.pdf', $encoded);
        $this->assertStringNotContainsString('application/pdf', $encoded);
        $this->assertStringNotContainsString((string) $document->storage_key, $encoded);
        $this->assertStringNotContainsString((string) $document->checksum_sha256, $encoded);
    }

    public function test_each_access_gets_its_own_outbox_row_keyed_on_its_own_access_event(): void
    {
        $document = $this->acceptedDocument();
        $actor = new ActorContext(identityReference: 42, roles: ['operator']);

        $first = (new RecordDocumentAccess)->record($document, $actor, DocumentAccessPurpose::View, AuditOutcome::Allowed);
        $second = (new RecordDocumentAccess)->record($document, $actor, DocumentAccessPurpose::View, AuditOutcome::Allowed);

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(2, OutboxEvent::query()->where('event_name', 'document.accessed.v1')->count());
        $this->assertSame(
            ["access:{$first->getKey()}", "access:{$second->getKey()}"],
            OutboxEvent::query()
                ->where('event_name', 'document.accessed.v1')
                ->orderBy('id')
                ->pluck('idempotency_key')
                ->all(),
        );
    }

    public function test_a_denied_access_is_recorded_without_publishing_a_document_accessed_event(): void
    {
        (new RecordDocumentAccess)->record(
            document: $this->acceptedDocument(),
            actor: new ActorContext(identityReference: 99, roles: ['admin']),
            purpose: DocumentAccessPurpose::Download,
            outcome: AuditOutcome::Denied,
        );

        $this->assertSame(1, DocumentAccessEvent::query()->count());
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'document.accessed.v1')->count());
    }

    private function acceptedDocument(): Document
    {
        $documentId = (string) Str::uuid();

        DB::table('documents')->insert([
            'id' => $documentId,
            'document_kind' => 'DEATH_CERTIFICATE',
            'state' => 'ACCEPTED',
            'owner_type' => 'order',
            'owner_id' => 'order-1',
            'original_filename' => 'akta-kematian.pdf',
            'storage_prefix' => 'accepted',
            'storage_key' => 'opaque-key-'.Str::random(8),
            'size_bytes' => 1024,
            'mime_declared' => 'application/pdf',
            'mime_verified' => 'application/pdf',
            'checksum_sha256' => str_repeat('a', 64),
            'scanner_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Document::query()->findOrFail($documentId);
    }
}
