<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault;

use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentValidator;
use App\Platform\DocumentVault\Exceptions\DocumentValidationException;
use Tests\TestCase;

final class DocumentValidatorTest extends TestCase
{
    public function test_a_genuine_pdf_is_accepted_and_returns_the_verified_mime_type(): void
    {
        $validator = new DocumentValidator;
        $stream = $this->streamFor($this->minimalPdf());

        $mimeVerified = $validator->validate(DocumentKind::Ktp, 'ktp-scan.pdf', strlen($this->minimalPdf()), $stream);

        $this->assertSame('application/pdf', $mimeVerified);
    }

    public function test_it_rejects_a_declared_mime_that_does_not_match_verified_content(): void
    {
        $validator = new DocumentValidator;
        $pdf = $this->minimalPdf();
        $stream = $this->streamFor($pdf);

        try {
            $validator->validate(DocumentKind::Ktp, 'ktp-scan.pdf', strlen($pdf), $stream, 'image/png');
            $this->fail('Expected DocumentValidationException for a declared MIME mismatch.');
        } catch (DocumentValidationException $exception) {
            $this->assertSame('declared_mime_mismatch', $exception->reason());
        }
    }

    public function test_it_rejects_allowed_but_different_declared_and_actual_mime_values(): void
    {
        $validator = new DocumentValidator;
        $zip = $this->minimalZip();
        $stream = $this->streamFor($zip);

        try {
            $validator->validate(
                DocumentKind::GraveImport,
                'grave-import.xlsx',
                strlen($zip),
                $stream,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );
            $this->fail('Expected DocumentValidationException for declared/actual MIME mismatch.');
        } catch (DocumentValidationException $exception) {
            $this->assertSame('declared_mime_mismatch', $exception->reason());
        }
    }

    public function test_a_genuine_png_is_accepted_for_a_kind_that_allows_images(): void
    {
        $validator = new DocumentValidator;
        $png = $this->minimalPng();
        $stream = $this->streamFor($png);

        $mimeVerified = $validator->validate(DocumentKind::ProductImage, 'listing.png', strlen($png), $stream);

        $this->assertSame('image/png', $mimeVerified);
    }

    public function test_it_rejects_a_zip_disguised_with_a_pdf_extension(): void
    {
        $validator = new DocumentValidator;
        $zip = $this->minimalZip();
        $stream = $this->streamFor($zip);

        try {
            $validator->validate(DocumentKind::Ktp, 'identity.pdf', strlen($zip), $stream);
            $this->fail('Expected DocumentValidationException for a MIME-spoofed upload.');
        } catch (DocumentValidationException $exception) {
            $this->assertSame('mime_not_allowed', $exception->reason());
            $this->assertSame(DocumentKind::Ktp, $exception->kind());
        }
    }

    public function test_it_rejects_an_oversized_file(): void
    {
        $validator = new DocumentValidator;
        $stream = $this->streamFor($this->minimalPdf());

        try {
            $validator->validate(
                DocumentKind::Ktp,
                'identity.pdf',
                DocumentKind::Ktp->maxSizeBytes() + 1,
                $stream,
            );
            $this->fail('Expected DocumentValidationException for an oversized upload.');
        } catch (DocumentValidationException $exception) {
            $this->assertSame('size_exceeded', $exception->reason());
        }
    }

    public function test_it_rejects_an_extension_not_allowed_for_the_kind(): void
    {
        $validator = new DocumentValidator;
        $csv = "a,b,c\n1,2,3\n";
        $stream = $this->streamFor($csv);

        try {
            $validator->validate(DocumentKind::Ktp, 'identity.csv', strlen($csv), $stream);
            $this->fail('Expected DocumentValidationException for a disallowed extension.');
        } catch (DocumentValidationException $exception) {
            $this->assertSame('extension_not_allowed', $exception->reason());
        }
    }

    public function test_it_rejects_a_script_extension_for_an_identity_document(): void
    {
        $validator = new DocumentValidator;
        $stream = $this->streamFor('<?php echo "not a document"; ?>');

        try {
            $validator->validate(DocumentKind::Ktp, 'identity.php', 30, $stream);
            $this->fail('Expected DocumentValidationException for a script extension.');
        } catch (DocumentValidationException $exception) {
            $this->assertSame('extension_not_allowed', $exception->reason());
        }
    }

    public function test_it_rejects_content_that_does_not_match_its_claimed_extension(): void
    {
        // Both .png and .jpg are individually acceptable for ProductImage,
        // so this can only be caught by the extension<->actual-type
        // cross-check, not by the per-kind MIME allowlist alone.
        $validator = new DocumentValidator;
        $jpeg = $this->minimalJpeg();
        $stream = $this->streamFor($jpeg);

        try {
            $validator->validate(DocumentKind::ProductImage, 'listing.png', strlen($jpeg), $stream);
            $this->fail('Expected DocumentValidationException for an extension/content mismatch.');
        } catch (DocumentValidationException $exception) {
            $this->assertSame('extension_mime_mismatch', $exception->reason());
        }
    }

    public function test_the_stream_is_rewound_after_a_successful_validation_for_the_caller_to_reuse(): void
    {
        $validator = new DocumentValidator;
        $pdf = $this->minimalPdf();
        $stream = $this->streamFor($pdf);

        $validator->validate(DocumentKind::Ktp, 'identity.pdf', strlen($pdf), $stream);

        $this->assertSame($pdf, stream_get_contents($stream));
    }

    private function minimalPdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }

    private function minimalPng(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $data = ob_get_clean();
        imagedestroy($image);

        return (string) $data;
    }

    private function minimalJpeg(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image);
        $data = ob_get_clean();
        imagedestroy($image);

        return (string) $data;
    }

    private function minimalZip(): string
    {
        return "PK\x03\x04\x14\x00\x00\x00\x08\x00".str_repeat("\x00", 64);
    }

    /**
     * @return resource
     */
    private function streamFor(string $content)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
