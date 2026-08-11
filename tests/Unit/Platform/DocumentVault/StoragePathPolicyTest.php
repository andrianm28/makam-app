<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault;

use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\StoragePathPolicy;
use Tests\TestCase;

final class StoragePathPolicyTest extends TestCase
{
    public function test_quarantine_path_uses_the_quarantine_prefix(): void
    {
        $policy = new StoragePathPolicy;

        $this->assertSame(
            'KTP/quarantine/opaque-key-123',
            $policy->quarantinePath(DocumentKind::Ktp, 'opaque-key-123'),
        );
    }

    public function test_accepted_path_uses_the_accepted_prefix(): void
    {
        $policy = new StoragePathPolicy;

        $this->assertSame(
            'KTP/accepted/opaque-key-123',
            $policy->acceptedPath(DocumentKind::Ktp, 'opaque-key-123'),
        );
    }

    public function test_quarantine_and_accepted_paths_never_collide_for_the_same_key(): void
    {
        $policy = new StoragePathPolicy;

        $this->assertNotSame(
            $policy->quarantinePath(DocumentKind::GraveImport, 'same-key'),
            $policy->acceptedPath(DocumentKind::GraveImport, 'same-key'),
        );
    }
}
