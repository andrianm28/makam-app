<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\Exceptions\ProductPhotoFailedScanException;
use App\Platform\DocumentVault\Contracts\MalwareScanner;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\ScanVerdict;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * VAULT-02 — the one malware-scan choke point for marketplace product
 * photos. See `Exceptions\ProductPhotoFailedScanException`'s own doc block
 * for why this stays on the `public` disk instead of routing through the
 * platform document vault.
 *
 * Deliberately reuses ONLY `Contracts\MalwareScanner` — the same interface
 * (and, on this combined dev/staging host, the same `Adapters\MockScanner`
 * binding) `Actions\ScanDocument` scans restricted vault documents with —
 * never `Actions\DocumentValidator`. `DocumentValidator`'s allowlist for
 * `DocumentKind::ProductImage` is `jpg/jpeg/png` only, narrower than
 * `ProductForm`'s own `acceptedFileTypes(['image/jpeg', 'image/png',
 * 'image/webp'])`; enforcing it here would silently reject a legitimate
 * WebP upload the form itself accepts. Content-type/extension validation
 * for this field stays Filament's own `image()`/`acceptedFileTypes()`
 * job; this class's only job is the malware check ADR-0023's amendment
 * requires.
 */
final readonly class ScanProductPhoto
{
    public function __construct(
        private MalwareScanner $scanner,
    ) {}

    /**
     * Scan the file already written to the `public` disk at `$diskPath`.
     *
     * A path that does not actually exist on this disk is left alone
     * (never scanned, never deleted): the nine seeded catalogue rows'
     * `photo_path` values point at `public/images/marketplace/*.svg` files
     * committed to the web root, not this disk — see `ProductForm`'s own
     * doc block on that pre-existing convention mismatch. Only a REAL
     * fresh upload — which always lands on this disk for real — is ever
     * scanned.
     *
     * @throws ProductPhotoFailedScanException when the scan verdict is not
     *                                         `ScanVerdict::Clean` — the
     *                                         file is deleted from the
     *                                         public disk first.
     */
    public function scan(string $diskPath): void
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($diskPath)) {
            return;
        }

        $stream = $disk->readStream($diskPath);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to open product photo [{$diskPath}] for scanning.");
        }

        try {
            $verdict = $this->scanner->scan(DocumentKind::ProductImage, $stream);
        } finally {
            fclose($stream);
        }

        if ($verdict !== ScanVerdict::Clean) {
            $disk->delete($diskPath);

            throw ProductPhotoFailedScanException::forVerdict($verdict);
        }
    }
}
