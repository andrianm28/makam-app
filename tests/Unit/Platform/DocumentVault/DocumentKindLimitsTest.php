<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault;

use App\Platform\DocumentVault\DocumentKind;
use Tests\TestCase;

final class DocumentKindLimitsTest extends TestCase
{
    public function test_every_kind_declares_a_positive_size_cap_and_non_empty_allowlists(): void
    {
        foreach (DocumentKind::cases() as $kind) {
            $this->assertGreaterThan(0, $kind->maxSizeBytes(), "{$kind->value} must declare a positive size cap.");
            $this->assertNotEmpty($kind->allowedExtensions(), "{$kind->value} must declare at least one extension.");
            $this->assertNotEmpty($kind->allowedMimeTypes(), "{$kind->value} must declare at least one MIME type.");
            $this->assertTrue($kind->scannerRequired(), "{$kind->value} must require scanning.");

            foreach ($kind->allowedExtensions() as $extension) {
                $this->assertSame(
                    strtolower($extension),
                    $extension,
                    "{$kind->value} extensions must be lower-case."
                );
                $this->assertStringNotContainsString('.', $extension);
            }
        }
    }

    public function test_the_grave_import_cap_is_larger_than_the_identity_document_caps(): void
    {
        $this->assertGreaterThan(DocumentKind::Ktp->maxSizeBytes(), DocumentKind::GraveImport->maxSizeBytes());
        $this->assertGreaterThan(DocumentKind::Kk->maxSizeBytes(), DocumentKind::GraveImport->maxSizeBytes());
        $this->assertGreaterThan(
            DocumentKind::DeathCertificate->maxSizeBytes(),
            DocumentKind::GraveImport->maxSizeBytes(),
        );
    }

    public function test_no_kind_allows_an_executable_or_script_extension(): void
    {
        $forbidden = ['php', 'exe', 'sh', 'bat', 'js', 'html', 'htm', 'phar'];

        foreach (DocumentKind::cases() as $kind) {
            foreach ($kind->allowedExtensions() as $extension) {
                $this->assertNotContains(
                    $extension,
                    $forbidden,
                    "{$kind->value} must never allow the executable/script extension [{$extension}]."
                );
            }
        }
    }
}
