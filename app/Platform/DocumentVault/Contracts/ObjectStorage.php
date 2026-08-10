<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Contracts;

/**
 * Provider-neutral private object storage boundary (`file-upload-pipeline.md`
 * §3/§10, plan.md's `ObjectStorage`/`ADR-0033` provider-neutrality
 * precedent). The combined dev/staging host binds this to
 * `Adapters\LocalFilesystemObjectStorage`; production later swaps in a real
 * S3-compatible adapter via config only (Task 8) — no consumer of this
 * interface may assume filesystem semantics.
 *
 * `put()` and `copy()` are deliberately the only ways bytes land in storage.
 * There is no dedicated "write to accepted" method: the only way a document
 * ever reaches the `accepted/` prefix is `copy()` from its `quarantine/`
 * path, called exclusively by the (Task 5) promotion Action after a CLEAN
 * scan verdict — this is what keeps AC1's "no direct path to accepted
 * storage" true at the adapter boundary, not only in the calling Action.
 * Every implementation MUST structurally reject a `put()` call whose path
 * names the `accepted/` prefix (not merely rely on callers behaving) —
 * `Adapters\LocalFilesystemObjectStorage::put()` does this by rejecting any
 * path with an `accepted` segment before it touches the filesystem.
 *
 * All paths are the opaque, provider-neutral keys produced by
 * `StoragePathResolver` — never a client-supplied filename or path.
 */
interface ObjectStorage
{
    /**
     * Write the full contents of `$stream` to `$path`, creating any
     * intermediate directory structure the adapter needs.
     *
     * @param  resource  $stream  A readable stream positioned at the start
     *                            of the content to persist.
     */
    public function put(string $path, $stream): void;

    /**
     * Copy the object at `$sourcePath` to `$destinationPath` without
     * disturbing the source. This is the only operation permitted to target
     * the `accepted/` prefix (see class doc block).
     */
    public function copy(string $sourcePath, string $destinationPath): void;

    /**
     * Open a private object for a scanner or checksum consumer.
     *
     * @return resource A readable stream positioned at the start.
     */
    public function read(string $path);

    /**
     * Return the SHA-256 digest of a private object without exposing its
     * contents to the caller.
     */
    public function checksum(string $path): string;

    /**
     * Permanently remove the object at `$path`.
     */
    public function delete(string $path): void;

    /**
     * Remove an object if present. Missing objects are a successful no-op so
     * cleanup jobs can safely retry after partial completion.
     */
    public function deleteIfExists(string $path): void;
}
