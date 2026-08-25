<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault;

use App\Platform\DocumentVault\Contracts\StoragePathResolver;

/**
 * The single concrete implementation of `StoragePathResolver`
 * (`task-3-brief.md` ambiguity ruling 1) and the only place in this module
 * that spells out the literal `quarantine`/`accepted` lifecycle prefix
 * strings. Every other class that needs a document's storage path goes
 * through this policy rather than concatenating a prefix itself, so "only
 * the promotion Action may reference the accepted/ prefix" stays true as a
 * matter of "there is exactly one method that can produce that string,"
 * not merely a convention nobody enforces.
 *
 * Produces the object key `Adapters\LocalFilesystemObjectStorage` (and any
 * future real object-storage adapter) writes under:
 * `{kind}/{quarantine|accepted}/{storageKey}`, joined by the adapter under
 * its own private root (`storage/app/private/documents/...` locally).
 */
final class StoragePathPolicy implements StoragePathResolver
{
    private const string QUARANTINE_PREFIX = 'quarantine';

    private const string ACCEPTED_PREFIX = 'accepted';

    public function quarantinePath(DocumentKind $kind, string $storageKey): string
    {
        return $this->path($kind, self::QUARANTINE_PREFIX, $storageKey);
    }

    public function acceptedPath(DocumentKind $kind, string $storageKey): string
    {
        return $this->path($kind, self::ACCEPTED_PREFIX, $storageKey);
    }

    private function path(DocumentKind $kind, string $prefix, string $storageKey): string
    {
        return "{$kind->value}/{$prefix}/{$storageKey}";
    }
}
