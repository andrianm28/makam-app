<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\ScanVerdict;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DocumentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_vault_enums_match_the_closed_lists(): void
    {
        $this->assertSame([
            'KTP',
            'KK',
            'DEATH_CERTIFICATE',
            'PAYMENT_PROOF',
            'AGREEMENT',
            'CERTIFICATE',
            'VENDOR_EVIDENCE',
            'PRODUCT_IMAGE',
            'GRAVE_IMPORT',
        ], array_column(DocumentKind::cases(), 'value'));

        $this->assertSame([
            'UPLOADING',
            'QUARANTINED',
            'SCANNING',
            'ACCEPTED',
            'REJECTED',
            'EXPIRED',
            'DELETED',
        ], array_column(DocumentState::cases(), 'value'));

        $this->assertSame([
            'CLEAN',
            'INFECTED',
            'SUSPICIOUS',
            'ERROR',
        ], array_column(ScanVerdict::cases(), 'value'));
    }

    public function test_the_database_rejects_an_unknown_document_kind(): void
    {
        $this->skipUnlessPostgres('documents_document_kind_check');

        $this->expectException(QueryException::class);

        DB::table('documents')->insert($this->documentAttributes([
            'document_kind' => 'NOT_A_DOCUMENT_KIND',
        ]));
    }

    public function test_document_retention_controls_and_scan_checksum_are_persisted(): void
    {
        $this->assertTrue(Schema::hasColumns('documents', ['legal_hold', 'evidence_hold']));
        $this->assertTrue(Schema::hasColumn('document_scans', 'checksum_sha256'));
    }

    public function test_postgresql_rejects_query_builder_updates_to_scan_evidence(): void
    {
        $this->skipUnlessPostgres('document_scans_append_only_trigger');

        $scanId = $this->persistedScanId();

        $this->expectException(QueryException::class);

        DB::table('document_scans')->where('id', $scanId)->update(['attempt' => 2]);
    }

    public function test_postgresql_rejects_query_builder_deletes_to_scan_evidence(): void
    {
        $this->skipUnlessPostgres('document_scans_append_only_trigger');

        $scanId = $this->persistedScanId();

        $this->expectException(QueryException::class);

        DB::table('document_scans')->where('id', $scanId)->delete();
    }

    public function test_the_database_rejects_an_unknown_document_state(): void
    {
        $this->skipUnlessPostgres('documents_state_check');

        $this->expectException(QueryException::class);

        DB::table('documents')->insert($this->documentAttributes([
            'state' => 'NOT_A_DOCUMENT_STATE',
        ]));
    }

    public function test_the_database_rejects_an_unknown_scan_verdict(): void
    {
        $this->skipUnlessPostgres('document_scans_verdict_check');

        $documentId = (string) Str::uuid();
        DB::table('documents')->insert($this->documentAttributes([
            'id' => $documentId,
        ]));

        $this->expectException(QueryException::class);

        DB::table('document_scans')->insert([
            'document_id' => $documentId,
            'scanner_name' => 'fixture-scanner',
            'scanner_engine_version' => '1.0.0',
            'verdict' => 'NOT_A_SCAN_VERDICT',
            'evidence' => json_encode(['reason' => 'fixture'], JSON_THROW_ON_ERROR),
            'attempt' => 1,
            'scanned_at' => now(),
        ]);
    }

    public function test_the_partial_unique_index_rejects_a_duplicate_live_client_upload_id(): void
    {
        $clientUploadId = 'resume-token-123';

        DB::table('documents')->insert($this->documentAttributes([
            'client_upload_id' => $clientUploadId,
        ]));

        $this->expectException(QueryException::class);

        DB::table('documents')->insert($this->documentAttributes([
            'client_upload_id' => $clientUploadId,
        ]));
    }

    public function test_the_partial_unique_index_allows_multiple_null_client_upload_ids(): void
    {
        DB::table('documents')->insert($this->documentAttributes([
            'client_upload_id' => null,
        ]));
        DB::table('documents')->insert($this->documentAttributes([
            'client_upload_id' => null,
        ]));

        $this->assertSame(2, DB::table('documents')->count());
    }

    public function test_a_document_cannot_be_deleted_while_a_scan_attempt_references_it(): void
    {
        $documentId = (string) Str::uuid();
        DB::table('documents')->insert($this->documentAttributes([
            'id' => $documentId,
        ]));
        DB::table('document_scans')->insert([
            'document_id' => $documentId,
            'scanner_name' => 'fixture-scanner',
            'scanner_engine_version' => '1.0.0',
            'verdict' => 'CLEAN',
            'evidence' => json_encode(['reason' => 'fixture'], JSON_THROW_ON_ERROR),
            'attempt' => 1,
            'scanned_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('documents')->where('id', $documentId)->delete();
    }

    public function test_the_database_rejects_a_signed_url_grant_beyond_five_minutes(): void
    {
        $this->skipUnlessPostgres('signed_url_grants_expires_at_check');

        $documentId = (string) Str::uuid();
        DB::table('documents')->insert($this->documentAttributes([
            'id' => $documentId,
        ]));

        $createdAt = CarbonImmutable::parse('2026-08-09 12:00:00');

        $this->expectException(QueryException::class);

        DB::table('signed_url_grants')->insert([
            'document_id' => $documentId,
            'purpose' => 'DOWNLOAD',
            'token' => 'opaque-token-123',
            'expires_at' => $createdAt->addSeconds(301),
            'consumed_at' => null,
            'created_at' => $createdAt,
        ]);
    }

    public function test_the_database_rejects_an_unknown_access_event_purpose(): void
    {
        $this->skipUnlessPostgres('document_access_events_purpose_check');

        $documentId = (string) Str::uuid();
        DB::table('documents')->insert($this->documentAttributes([
            'id' => $documentId,
        ]));

        $this->expectException(QueryException::class);

        DB::table('document_access_events')->insert([
            'document_id' => $documentId,
            'actor_ref' => 'actor-123',
            'actor_role' => 'admin',
            'purpose' => 'PREVIEW',
            'outcome' => 'allowed',
            'ip_address' => '192.0.2.1',
            'occurred_at' => now(),
        ]);
    }

    public function test_the_database_rejects_an_unknown_access_event_outcome(): void
    {
        $this->skipUnlessPostgres('document_access_events_outcome_check');

        $documentId = (string) Str::uuid();
        DB::table('documents')->insert($this->documentAttributes([
            'id' => $documentId,
        ]));

        $this->expectException(QueryException::class);

        DB::table('document_access_events')->insert([
            'document_id' => $documentId,
            'actor_ref' => 'actor-123',
            'actor_role' => 'admin',
            'purpose' => 'VIEW',
            'outcome' => 'unknown',
            'ip_address' => '192.0.2.1',
            'occurred_at' => now(),
        ]);
    }

    public function test_a_document_cannot_be_deleted_while_a_signed_url_grant_references_it(): void
    {
        $documentId = (string) Str::uuid();
        DB::table('documents')->insert($this->documentAttributes([
            'id' => $documentId,
        ]));

        $createdAt = CarbonImmutable::parse('2026-08-09 12:00:00');
        DB::table('signed_url_grants')->insert([
            'document_id' => $documentId,
            'purpose' => 'DOWNLOAD',
            'token' => 'opaque-token-restrict',
            'expires_at' => $createdAt->addMinutes(5),
            'consumed_at' => null,
            'created_at' => $createdAt,
        ]);

        $this->expectException(QueryException::class);

        DB::table('documents')->where('id', $documentId)->delete();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function documentAttributes(array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'document_kind' => 'KTP',
            'state' => 'UPLOADING',
            'owner_type' => 'booking_draft',
            'owner_id' => 'draft-123',
            'original_filename' => 'identity.pdf',
            'storage_prefix' => 'quarantine',
            'storage_key' => 'opaque-key-123',
            'size_bytes' => 1024,
            'mime_declared' => 'application/pdf',
            'mime_verified' => null,
            'checksum_sha256' => null,
            'client_upload_id' => null,
            'scanner_required' => true,
            'retention_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function skipUnlessPostgres(string $constraint): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped("{$constraint} is only added on the pgsql driver.");
        }
    }

    private function persistedScanId(): int
    {
        $documentId = (string) Str::uuid();
        DB::table('documents')->insert($this->documentAttributes(['id' => $documentId]));

        return (int) DB::table('document_scans')->insertGetId([
            'document_id' => $documentId,
            'scanner_name' => 'fixture-scanner',
            'scanner_engine_version' => '1.0.0',
            'verdict' => 'CLEAN',
            'evidence' => json_encode(['reason' => 'fixture'], JSON_THROW_ON_ERROR),
            'checksum_sha256' => str_repeat('a', 64),
            'attempt' => 1,
            'scanned_at' => now(),
        ]);
    }
}
