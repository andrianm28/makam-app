<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault;

use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\Adapters\MockScanner;
use App\Platform\DocumentVault\Contracts\MalwareScanner;
use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\Providers\DocumentVaultServiceProvider;
use Tests\TestCase;

final class Task8DocumentVaultTest extends TestCase
{
    public function test_provider_resolves_the_configured_storage_and_scanner_bindings(): void
    {
        config([
            'document-vault.object_storage' => LocalFilesystemObjectStorage::class,
            'document-vault.malware_scanner' => MockScanner::class,
        ]);

        (new DocumentVaultServiceProvider($this->app))->register();

        $this->assertInstanceOf(LocalFilesystemObjectStorage::class, $this->app->make(ObjectStorage::class));
        $this->assertInstanceOf(MockScanner::class, $this->app->make(MalwareScanner::class));
    }

    public function test_revoke_sql_documents_the_exact_access_event_mutation_boundary(): void
    {
        $sql = file_get_contents(base_path('sql/revoke-document-mutations.sql'));

        $this->assertIsString($sql);
        $this->assertStringContainsString('REVOKE UPDATE, DELETE ON document_access_events FROM <app_role>;', $sql);
        $this->assertStringContainsString('GRANT SELECT, INSERT ON document_access_events TO <app_role>;', $sql);
        $this->assertStringContainsString('SET ROLE <app_role>;', $sql);
        $this->assertStringContainsString('UPDATE document_access_events', $sql);
        $this->assertStringContainsString('DELETE FROM document_access_events', $sql);
    }

    public function test_event_catalog_advances_to_v05_with_the_three_document_events(): void
    {
        $catalog = file_get_contents(base_path('docs/contracts/event-catalog.md'));

        $this->assertIsString($catalog);
        $this->assertStringContainsString('# Event Catalog — v0.5', $catalog);
        $this->assertStringContainsString('| `document.uploaded.v1` |', $catalog);
        $this->assertStringContainsString('| `document.accepted.v1` |', $catalog);
        $this->assertStringContainsString('| `document.deleted.v1` |', $catalog);
        $this->assertStringContainsString('| `document.accessed.v1` |', $catalog);
    }

    public function test_postgresql_app_role_mutation_execution_is_explicitly_not_tested_without_role_provisioning(): void
    {
        $this->markTestSkipped(
            'NOT TESTED: requires a distinct PostgreSQL application role and applied revoke SQL; the local test environment does not provision that role.',
        );
    }
}
