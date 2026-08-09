<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Adapters;

use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\Exceptions\ObjectStorageException;
use Illuminate\Support\Facades\URL;

/**
 * Filesystem `ObjectStorage` adapter for the combined dev/staging host
 * (`file-upload-pipeline.md` §9, plan.md's `LocalPrivateStorage`). Writes
 * every object under `storage/app/private/documents/{kind}/{prefix}/{key}`
 * — never under `public/`, never behind `storage:link`
 * (`task-3-brief.md` ambiguity ruling 6). Nothing in this class registers a
 * route or otherwise makes these files reachable over HTTP; the only way a
 * document leaves this adapter is through `temporaryUrl()`'s literal path,
 * which Task 7's private, access-audited route resolves.
 *
 * The default root is `storage_path('app/private/documents')`; the
 * constructor also accepts an explicit root so tests can point this adapter
 * at a throwaway directory instead of touching the real dev storage tree
 * (`task-3-brief.md`: "directly constructible for tests without container
 * bindings").
 */
final class LocalFilesystemObjectStorage implements ObjectStorage
{
    public function __construct(private readonly string $root = '') {}

    public function put(string $path, $stream): void
    {
        $this->assertNotAcceptedPrefix($path);

        $destination = $this->absolutePath($path);
        $this->ensureDirectoryExists(dirname($destination));

        $handle = fopen($destination, 'wb');

        if ($handle === false) {
            throw ObjectStorageException::writeFailed($path);
        }

        try {
            if (stream_copy_to_stream($stream, $handle) === false) {
                throw ObjectStorageException::writeFailed($path);
            }
        } finally {
            fclose($handle);
        }

        chmod($destination, 0600);
    }

    public function copy(string $sourcePath, string $destinationPath): void
    {
        $source = $this->absolutePath($sourcePath);
        $destination = $this->absolutePath($destinationPath);

        if (! is_file($source)) {
            throw ObjectStorageException::sourceMissing($sourcePath);
        }

        $this->ensureDirectoryExists(dirname($destination));

        if (! copy($source, $destination)) {
            throw ObjectStorageException::copyFailed($sourcePath, $destinationPath);
        }

        chmod($destination, 0600);
    }

    public function delete(string $path): void
    {
        $absolute = $this->absolutePath($path);

        if (! is_file($absolute)) {
            throw ObjectStorageException::sourceMissing($path);
        }

        if (! unlink($absolute)) {
            throw ObjectStorageException::deleteFailed($path);
        }
    }

    /**
     * Builds the literal, non-route-dependent URL to Task 7's private
     * download endpoint (`task-3-brief.md` ambiguity ruling 3). `$documentId`
     * and `$token` are minted by the caller; this adapter never generates
     * either.
     */
    public function temporaryUrl(string $documentId, string $token): string
    {
        return URL::to("/internal/documents/{$documentId}/download/{$token}");
    }

    private function root(): string
    {
        return $this->root !== '' ? $this->root : storage_path('app/private/documents');
    }

    private function absolutePath(string $path): string
    {
        $this->assertSafeRelativePath($path);

        return $this->root().'/'.$path;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, recursive: true) && ! is_dir($directory)) {
            throw ObjectStorageException::writeFailed($directory);
        }
    }

    /**
     * Structurally enforces AC1's "no direct path to accepted storage": the
     * only route into the `accepted/` prefix is `copy()`'s destination
     * argument. `put()` calls this on the raw, caller-supplied path before
     * it ever reaches `absolutePath()`.
     */
    private function assertNotAcceptedPrefix(string $path): void
    {
        if (in_array('accepted', explode('/', $path), true)) {
            throw ObjectStorageException::acceptedPrefixForbidden($path);
        }
    }

    /**
     * Path-traversal and shape defense applied on every path-taking entry
     * point (`put`, `copy`'s source and destination, `delete`) via
     * `absolutePath()`. This class must defend itself independently of
     * caller correctness — Tasks 4-7 add callers this class cannot see —
     * so a caller-supplied path is never trusted to already be safe.
     *
     * Rejects: empty paths, absolute paths (leading `/`), and any segment
     * that is empty, `.`, or `..`.
     */
    private function assertSafeRelativePath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/')) {
            throw ObjectStorageException::invalidPath($path);
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw ObjectStorageException::invalidPath($path);
            }
        }
    }
}
