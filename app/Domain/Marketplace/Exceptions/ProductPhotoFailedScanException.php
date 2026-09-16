<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Exceptions;

use App\Platform\DocumentVault\ScanVerdict;
use RuntimeException;

/**
 * Thrown by `Actions\ScanProductPhoto` (VAULT-02) when a marketplace product
 * photo's own `MalwareScanner::scan()` verdict is anything other than
 * `ScanVerdict::Clean`.
 *
 * ---------------------------------------------------------------------------
 * Why this file is still reachable by the public — VAULT-02's chosen fix
 * ---------------------------------------------------------------------------
 * Unlike every restricted document in the platform document vault, a
 * marketplace catalogue photo is DESIGNED to be publicly viewable by an
 * unauthenticated shopper — routing it through the vault's quarantine →
 * scan → promote → audited-download-route pipeline would break that (see
 * `docs/adr/0023-quarantine-all-untrusted-files.md`'s VAULT-02 amendment).
 * The chosen fix keeps the file on the `public` disk (so it stays instantly,
 * anonymously servable) but adds the SAME `MalwareScanner` malware check the
 * vault itself uses, at the one synchronous choke point every write to
 * `products.photo_path` already passes through: `Product`'s own `saving`
 * model event (see that class's doc block). A non-CLEAN verdict deletes the
 * just-written file from the public disk and refuses the save — the file
 * is never left reachable, and the database is never left pointing at a
 * variant of "we accepted it anyway."
 */
final class ProductPhotoFailedScanException extends RuntimeException
{
    public static function forVerdict(ScanVerdict $verdict): self
    {
        return new self(
            "Product photo rejected: malware scan verdict was [{$verdict->value}], not CLEAN. ".
            'The uploaded file has been removed from public storage.'
        );
    }
}
