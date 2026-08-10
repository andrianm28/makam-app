<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Actions;

use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\Contracts\StoragePathResolver;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\DocumentValidator;
use App\Platform\DocumentVault\Jobs\ScanDocumentJob;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use App\Platform\Outbox\OutboxQueueName;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Quarantine-first upload entry point for the platform document vault
 * (AC1, AC3, AC13, AC14; `file-upload-pipeline.md` §1-2). The single write
 * path for the upload/resume transitions this task owns — see
 * `Models\Document`'s own class doc block for the "one write API per table"
 * discipline this Action is the designated holder of.
 *
 * ---------------------------------------------------------------------------
 * AC1 — quarantine-first, structurally
 * ---------------------------------------------------------------------------
 * Every write goes through `StoragePathResolver::quarantinePath()`, never
 * `acceptedPath()`; every created row starts in `DocumentState::Quarantined`,
 * never `Accepted`. `Adapters\LocalFilesystemObjectStorage::put()` also
 * independently refuses any path naming the `accepted/` segment, so this is
 * defense in depth, not this class's word alone.
 *
 * ---------------------------------------------------------------------------
 * `UploadedFile|StreamInterface $file` — the brief's "Stream" type
 * ---------------------------------------------------------------------------
 * `task-4-brief.md`'s signature names the second parameter's type as
 * `UploadedFile|Stream` without defining what `Stream` is; no such class
 * exists anywhere in this codebase. `Psr\Http\Message\StreamInterface`
 * (`psr/http-message`, already a real dependency via `guzzlehttp/guzzle`,
 * confirmed loadable) is used here instead — the standard PHP interop
 * stream contract, and the natural fit for a non-HTTP caller (e.g. a future
 * `GRAVE_IMPORT` batch job reading a file already on disk) that has no
 * `UploadedFile` to hand. Flagged as a judgement call in the task report,
 * not a silent invention.
 *
 * `UploadedFile` supplies its own `original_filename`/`mime_declared`
 * (`getClientOriginalName()`/`getClientMimeType()`); a bare
 * `StreamInterface` carries neither, so `$meta['original_filename']`/
 * `$meta['mime_declared']` are REQUIRED when `$file` is a `StreamInterface`
 * (an `InvalidArgumentException` otherwise) and optional overrides when
 * `$file` is an `UploadedFile`.
 *
 * `size_bytes`/`checksum_sha256` are never taken from either input's own
 * self-reported metadata — both are computed from the stream's own bytes in
 * a single hashing pass (`digestAndSize()`, mirrors
 * `Adapters\MockScanner::digestAndSize()`), the same "never trust the
 * client, verify the actual bytes" posture `DocumentValidator` already
 * applies to MIME type. This also closes `task-3-report.md`'s deferred
 * finding that `DocumentValidator::validate()` alone does not cross-check
 * its caller-supplied `$sizeBytes` against the real stream length — this
 * Action is that caller, and it now always supplies a verified value.
 *
 * ---------------------------------------------------------------------------
 * AC13 — idempotent resume
 * ---------------------------------------------------------------------------
 * A matching `client_upload_id` short-circuits the whole "create a new row"
 * path: the SAME quarantine object (same `storage_key`) is re-validated and
 * overwritten with the retried attempt's bytes, the row's descriptive
 * storage columns are refreshed, but `state` is left untouched and no
 * second `Outbox::record()`/`ScanDocumentJob::dispatch()` happens. Calling
 * `Outbox::record()` again here would not just be redundant, it would throw
 * — `outbox_events.idempotency_key` is a real database UNIQUE constraint,
 * and this class's idempotency key is derived from the document id
 * (`"upload:{$document->id}"`), which cannot change across a resume. A
 * validation failure on resume throws before anything is persisted (the
 * whole method runs in one `DB::transaction()`), so a rejected retry never
 * clears the existing row or any sibling document for the same owner.
 *
 * ---------------------------------------------------------------------------
 * AC14 — GRAVE_IMPORT is not a special case
 * ---------------------------------------------------------------------------
 * `DocumentKind::GraveImport` runs through the exact same `createNew()`/
 * `resume()` code as every other kind; its larger `maxSizeBytes()` and its
 * `scannerRequired()` are read from `DocumentKind` itself, never branched
 * on here by kind.
 */
final readonly class UploadDocument
{
    public function __construct(
        private ObjectStorage $objectStorage,
        private StoragePathResolver $pathResolver,
        private DocumentValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $meta  Recognized keys: `original_filename`
     *                                      and `mime_declared` — see class doc block.
     */
    public function upload(
        DocumentKind $kind,
        UploadedFile|StreamInterface $file,
        string $ownerType,
        int|string $ownerId,
        ?string $clientUploadId,
        array $meta,
    ): Document {
        return DB::transaction(function () use ($kind, $file, $ownerType, $ownerId, $clientUploadId, $meta): Document {
            $existing = $this->findExisting($clientUploadId, $ownerType, (string) $ownerId);

            $resource = $this->resourceFrom($file);

            try {
                [$checksum, $sizeBytes] = $this->digestAndSize($resource);
                $originalFilename = $this->resolveOriginalFilename($file, $meta);
                $mimeDeclared = $this->resolveMimeDeclared($file, $meta);

                if ($existing instanceof Document) {
                    return $this->resume(
                        $existing,
                        $kind,
                        $resource,
                        $originalFilename,
                        $mimeDeclared,
                        $sizeBytes,
                        $checksum,
                    );
                }

                try {
                    // The unique client-upload index is the last line of
                    // defense when two transactions observe no token row at
                    // the same time. The savepoint lets the losing writer
                    // recover the committed winner without aborting the
                    // outer transaction.
                    return DB::transaction(fn (): Document => $this->createNew(
                        $kind,
                        $resource,
                        $ownerType,
                        $ownerId,
                        $clientUploadId,
                        $originalFilename,
                        $mimeDeclared,
                        $sizeBytes,
                        $checksum,
                    ));
                } catch (QueryException $exception) {
                    if ($clientUploadId === null || ! $this->isDuplicateClientUploadId($exception)) {
                        throw $exception;
                    }

                    $existing = $this->findExisting($clientUploadId, $ownerType, (string) $ownerId);

                    if (! $existing instanceof Document) {
                        throw $exception;
                    }

                    return $this->resume(
                        $existing,
                        $kind,
                        $resource,
                        $originalFilename,
                        $mimeDeclared,
                        $sizeBytes,
                        $checksum,
                    );
                }
            } finally {
                fclose($resource);
            }
        });
    }

    /**
     * AC13 resume branch — see class doc block.
     *
     * @param  resource  $resource
     */
    private function resume(
        Document $document,
        DocumentKind $kind,
        $resource,
        string $originalFilename,
        string $mimeDeclared,
        int $sizeBytes,
        string $checksum,
    ): Document {
        if (! in_array($document->state, [DocumentState::Uploading, DocumentState::Quarantined], true)) {
            throw new InvalidArgumentException(
                "Document {$document->id} cannot be resumed from state {$document->state->value}; a fresh scan is required.",
            );
        }

        if ($kind !== $document->document_kind) {
            throw new InvalidArgumentException(
                "\$kind ({$kind->value}) does not match the resumed document's original kind ({$document->document_kind->value}).",
            );
        }

        $mimeVerified = $this->validator->validate($kind, $originalFilename, $sizeBytes, $resource);

        $this->objectStorage->put($this->pathResolver->quarantinePath($kind, $document->storage_key), $resource);

        $document->fill([
            'original_filename' => $originalFilename,
            'size_bytes' => $sizeBytes,
            'mime_declared' => $mimeDeclared,
            'mime_verified' => $mimeVerified,
            'checksum_sha256' => $checksum,
        ]);
        $document->save();

        return $document;
    }

    /**
     * AC1/AC14 create-new branch — see class doc block.
     *
     * @param  resource  $resource
     */
    private function createNew(
        DocumentKind $kind,
        $resource,
        string $ownerType,
        int|string $ownerId,
        ?string $clientUploadId,
        string $originalFilename,
        string $mimeDeclared,
        int $sizeBytes,
        string $checksum,
    ): Document {
        $mimeVerified = $this->validator->validate($kind, $originalFilename, $sizeBytes, $resource);

        $storageKey = Str::random(40);

        $storagePath = $this->pathResolver->quarantinePath($kind, $storageKey);

        $this->objectStorage->put($storagePath, $resource);

        try {
            $document = Document::createQuarantined([
                'document_kind' => $kind,
                'owner_type' => $ownerType,
                'owner_id' => (string) $ownerId,
                'original_filename' => $originalFilename,
                // Literal 'quarantine', not composed via StoragePathResolver:
                // that interface's job is building object KEYS
                // (`{kind}/{prefix}/{key}`) for `ObjectStorage`, not sourcing
                // this column's value — see `StoragePathPolicy`'s own doc block
                // for what it does and does not own.
                'storage_prefix' => 'quarantine',
                'storage_key' => $storageKey,
                'size_bytes' => $sizeBytes,
                'mime_declared' => $mimeDeclared,
                'mime_verified' => $mimeVerified,
                'checksum_sha256' => $checksum,
                'client_upload_id' => $clientUploadId,
            ]);

            // AC12: a reference plus the kind only — never content, never the
            // original filename. `state` is deliberately the literal lowercase
            // string `task-4-brief.md` specifies verbatim.
            Outbox::record(
                eventName: 'document.uploaded.v1',
                eventVersion: 1,
                aggregateType: 'document',
                aggregateId: $document->id,
                data: [
                    'kind' => $kind->value,
                    'state' => 'quarantined',
                ],
                classification: OutboxClassification::Confidential,
                idempotencyKey: "upload:{$document->id}",
            );

            // Register with the database transaction manager rather than
            // dispatching now. This is equivalent to a queued job's
            // `afterCommit` flag and remains observable with Queue::fake(),
            // whose fake push method bypasses the queue driver's own
            // after-commit hook.
            DB::afterCommit(function () use ($document): void {
                ScanDocumentJob::dispatch($document->id)->onQueue(OutboxQueueName::Media->value);
            });

            return $document;
        } catch (Throwable $exception) {
            try {
                $this->objectStorage->delete($storagePath);
            } catch (Throwable) {
                // Preserve the original transaction/storage exception.
            }

            throw $exception;
        }
    }

    private function findExisting(?string $clientUploadId, string $ownerType, string $ownerId): ?Document
    {
        if ($clientUploadId === null) {
            return null;
        }

        $existing = Document::query()
            ->where('client_upload_id', $clientUploadId)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof Document) {
            return $existing;
        }

        $otherOwner = Document::query()
            ->where('client_upload_id', $clientUploadId)
            ->lockForUpdate()
            ->first();

        if ($otherOwner instanceof Document) {
            throw new InvalidArgumentException('The client upload token belongs to another owner.');
        }

        return null;
    }

    private function isDuplicateClientUploadId(QueryException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'client_upload_id');
    }

    private function resolveOriginalFilename(UploadedFile|StreamInterface $file, array $meta): string
    {
        if (isset($meta['original_filename'])) {
            return (string) $meta['original_filename'];
        }

        if ($file instanceof UploadedFile) {
            return $file->getClientOriginalName();
        }

        throw new InvalidArgumentException(
            "\$meta['original_filename'] is required when \$file is a StreamInterface.",
        );
    }

    private function resolveMimeDeclared(UploadedFile|StreamInterface $file, array $meta): string
    {
        if (isset($meta['mime_declared'])) {
            return (string) $meta['mime_declared'];
        }

        if ($file instanceof UploadedFile) {
            return $file->getClientMimeType();
        }

        throw new InvalidArgumentException(
            "\$meta['mime_declared'] is required when \$file is a StreamInterface.",
        );
    }

    /**
     * @return resource
     */
    private function resourceFrom(UploadedFile|StreamInterface $file)
    {
        if ($file instanceof UploadedFile) {
            $resource = fopen($file->getRealPath(), 'rb');

            if ($resource === false) {
                throw new InvalidArgumentException('Unable to open the uploaded file for reading.');
            }

            return $resource;
        }

        // Copy the PSR-7 stream instead of detaching it. `detach()` transfers
        // ownership and leaves common implementations unusable, which made
        // the old fallback call to `getContents()` fail after detachment.
        $temp = fopen('php://temp', 'r+b');

        if ($temp === false) {
            throw new InvalidArgumentException('Unable to create a temporary upload stream.');
        }

        if ($file->isSeekable()) {
            $file->rewind();
        }

        while (! $file->eof()) {
            $chunk = $file->read(8192);

            if ($chunk === '') {
                break;
            }

            if (fwrite($temp, $chunk) !== strlen($chunk)) {
                fclose($temp);
                throw new InvalidArgumentException('Unable to buffer the upload stream.');
            }
        }

        rewind($temp);

        return $temp;
    }

    /**
     * @param  resource  $resource
     * @return array{0: string, 1: int} The SHA-256 hex digest and the
     *                                  actual byte count, computed from the
     *                                  stream's own bytes — see class doc
     *                                  block. Rewinds `$resource` to the
     *                                  start before returning.
     */
    private function digestAndSize($resource): array
    {
        rewind($resource);

        $context = hash_init('sha256');
        $bytesRead = 0;

        while (! feof($resource)) {
            $chunk = fread($resource, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $bytesRead += strlen($chunk);
            hash_update($context, $chunk);
        }

        rewind($resource);

        return [hash_final($context), $bytesRead];
    }
}
