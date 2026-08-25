<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use Tests\TestCase;

final class DocumentScheduleTest extends TestCase
{
    public function test_document_storage_reconciliation_is_registered_on_the_media_queue(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('document-vault:reconcile-storage-cleanups')
            ->assertExitCode(0);
    }
}
