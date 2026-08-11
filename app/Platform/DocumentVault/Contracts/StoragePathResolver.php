<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Contracts;

use App\Platform\DocumentVault\DocumentKind;

/**
 * Resolves a document kind + lifecycle stage + opaque storage key into the
 * provider-neutral object key `ObjectStorage` operates on
 * (`task-3-brief.md` ambiguity ruling 1).
 *
 * `StoragePathPolicy` is this interface's single implementation and is the
 * one place in the codebase that names the literal `quarantine`/`accepted`
 * lifecycle prefixes — see its class doc block for the "only the promotion
 * Action may reference the accepted/ prefix" rule this exists to express.
 *
 * `$storageKey` is always the caller-supplied opaque, random key
 * (`documents.storage_key`) — never `original_filename` or any other
 * client-derived value (that column is display-only and is never a storage
 * key authority).
 */
interface StoragePathResolver
{
    /**
     * The quarantine-stage object key for a not-yet-accepted document.
     * Every upload is written here first and only here (AC1).
     */
    public function quarantinePath(DocumentKind $kind, string $storageKey): string;

    /**
     * The accepted-stage object key. Only the Task 5 promotion Action may
     * call this and pass the result to `ObjectStorage::copy()` — no other
     * caller in this codebase has a legitimate reason to name the
     * `accepted/` prefix.
     */
    public function acceptedPath(DocumentKind $kind, string $storageKey): string;
}
