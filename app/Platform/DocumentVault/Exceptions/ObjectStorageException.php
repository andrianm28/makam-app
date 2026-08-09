<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Exceptions;

use RuntimeException;

/**
 * Thrown by `Adapters\LocalFilesystemObjectStorage` when a `put`/`copy`/
 * `delete` operation cannot complete (missing source, unwritable directory,
 * disk failure). Messages reference only the opaque storage path (document
 * kind + lifecycle prefix + random key) — never document content or the
 * client-supplied original filename.
 */
final class ObjectStorageException extends RuntimeException
{
    public static function sourceMissing(string $path): self
    {
        return new self("Object storage source path does not exist: {$path}");
    }

    public static function writeFailed(string $path): self
    {
        return new self("Object storage write failed for path: {$path}");
    }

    public static function copyFailed(string $sourcePath, string $destinationPath): self
    {
        return new self("Object storage copy failed from [{$sourcePath}] to [{$destinationPath}]");
    }

    public static function deleteFailed(string $path): self
    {
        return new self("Object storage delete failed for path: {$path}");
    }

    /**
     * `put()` refused a path naming the `accepted/` lifecycle prefix — only
     * `copy()` may write there (AC1, `ObjectStorage` interface doc block).
     */
    public static function acceptedPrefixForbidden(string $path): self
    {
        return new self("Object storage put() may not target the accepted/ prefix: {$path}");
    }

    /**
     * A path failed traversal/shape validation: empty, absolute, or
     * containing a `.`/`..`/empty segment. This class must reject such
     * paths independently of caller correctness — see class doc block.
     */
    public static function invalidPath(string $path): self
    {
        return new self("Object storage path is invalid: {$path}");
    }
}
