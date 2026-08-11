<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use App\Platform\DocumentVault\Exceptions\DocumentAccessEventIsImmutableException;
use App\Platform\DocumentVault\Models\DocumentAccessEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DocumentAccessEventAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_on_a_persisted_row_throws(): void
    {
        $event = $this->persistedEvent();

        $this->expectException(DocumentAccessEventIsImmutableException::class);

        $event->update(['outcome' => 'denied']);
    }

    public function test_mutating_an_attribute_and_calling_save_on_a_persisted_row_throws(): void
    {
        $event = $this->persistedEvent();
        $event->outcome = 'denied';

        $this->expectException(DocumentAccessEventIsImmutableException::class);

        $event->save();
    }

    public function test_delete_on_a_persisted_row_throws(): void
    {
        $event = $this->persistedEvent();

        $this->expectException(DocumentAccessEventIsImmutableException::class);

        $event->delete();
    }

    public function test_document_cannot_be_deleted_while_an_access_event_references_it(): void
    {
        $event = $this->persistedEvent();

        $this->expectException(QueryException::class);

        DB::table('documents')->where('id', $event->document_id)->delete();
    }

    private function persistedEvent(): DocumentAccessEvent
    {
        $documentId = (string) Str::uuid();

        DB::table('documents')->insert([
            'id' => $documentId,
            'document_kind' => 'KTP',
            'state' => 'ACCEPTED',
            'owner_type' => 'booking_draft',
            'owner_id' => 'draft-123',
            'original_filename' => 'identity.pdf',
            'storage_prefix' => 'accepted',
            'storage_key' => 'opaque-key-123',
            'size_bytes' => 1024,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = DB::table('document_access_events')->insertGetId([
            'document_id' => $documentId,
            'actor_ref' => 'actor-123',
            'actor_role' => 'admin',
            'purpose' => 'VIEW',
            'outcome' => 'allowed',
            'ip_address' => '192.0.2.1',
            'occurred_at' => now(),
        ]);

        return DocumentAccessEvent::query()->findOrFail($eventId);
    }
}
