<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault;

use App\Platform\DocumentVault\Exceptions\DocumentValidationException;

/**
 * Upload-time validation for the document vault (AC3): size cap, extension
 * allowlist, and finfo-sniffed actual-content-type verification, all
 * per-kind (`DocumentKind::maxSizeBytes()`/`allowedExtensions()`/
 * `allowedMimeTypes()`). Every check reads `DocumentKind`'s tables rather
 * than a second, potentially-drifting copy (`task-3-brief.md` ambiguity
 * ruling 2).
 *
 * The declared MIME type is checked against the stream's own bytes, sniffed
 * with `finfo`/`FILEINFO_MIME_TYPE`; it never replaces byte verification.
 * Direct callers may omit it when no client metadata exists, while
 * `UploadDocument` always supplies it after resolving upload/stream metadata.
 * Two independent checks catch spoofing: the actual type must be in the
 * kind's allowlist at all, and it must also be the type the claimed extension
 * implies (`self::EXTENSION_MIME_MAP`) — so a `.pdf` that is really a zip, or
 * a `.png` that is really a `.jpg`, is rejected even when the sniffed type
 * would otherwise be acceptable for that kind in isolation.
 *
 * `$originalFilename` is used only to read its extension; it is never
 * treated as a storage-key authority (module-wide rule).
 */
final class DocumentValidator
{
    private const int SNIFF_BYTES = 8192;

    /**
     * Extension → the actual MIME types finfo may legitimately report for a
     * genuine file of that extension. Deliberately narrower per-extension
     * than `DocumentKind::allowedMimeTypes()`, which is only "acceptable
     * for this kind at all" — this map is "acceptable for this specific
     * claimed extension."
     *
     * @var array<string, list<string>>
     */
    private const array EXTENSION_MIME_MAP = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'csv' => ['text/csv', 'text/plain'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
    ];

    /**
     * @param  resource  $stream  A readable, seekable stream positioned at
     *                            the start of the file content. Left
     *                            rewound to the start on both success and
     *                            failure so the caller may reuse it (e.g.
     *                            to hand it to `ObjectStorage::put()`
     *                            immediately afterward).
     * @param  string|null  $declaredMimeType  Client metadata when available;
     *                                         null means no declaration exists.
     * @return string The finfo-verified actual MIME type, for the caller to
     *                persist as `documents.mime_verified`.
     *
     * @throws DocumentValidationException
     */
    public function validate(
        DocumentKind $kind,
        string $originalFilename,
        int $sizeBytes,
        $stream,
        ?string $declaredMimeType = null,
    ): string {
        if ($sizeBytes > $kind->maxSizeBytes()) {
            throw DocumentValidationException::sizeExceeded($kind);
        }

        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if (! in_array($extension, $kind->allowedExtensions(), true)) {
            throw DocumentValidationException::extensionNotAllowed($kind);
        }

        $actualMimeType = $this->sniffMimeType($stream);

        if (! in_array($actualMimeType, $kind->allowedMimeTypes(), true)) {
            throw DocumentValidationException::mimeNotAllowed($kind);
        }

        $expectedMimeTypes = self::EXTENSION_MIME_MAP[$extension] ?? [];

        if ($declaredMimeType !== null) {
            $declaredMimeType = strtolower(trim($declaredMimeType));

            if (
                ! in_array($declaredMimeType, $kind->allowedMimeTypes(), true)
                || ! in_array($declaredMimeType, $expectedMimeTypes, true)
            ) {
                throw DocumentValidationException::declaredMimeMismatch($kind);
            }

            $csvTextAlias = $extension === 'csv'
                && $declaredMimeType === 'text/csv'
                && $actualMimeType === 'text/plain';

            if ($declaredMimeType !== $actualMimeType && ! $csvTextAlias) {
                throw DocumentValidationException::declaredMimeMismatch($kind);
            }
        }

        if (! in_array($actualMimeType, $expectedMimeTypes, true)) {
            throw DocumentValidationException::extensionMimeMismatch($kind);
        }

        return $actualMimeType;
    }

    /**
     * @param  resource  $stream
     */
    private function sniffMimeType($stream): string
    {
        rewind($stream);
        $buffer = stream_get_contents($stream, self::SNIFF_BYTES);
        rewind($stream);

        if ($buffer === false || $buffer === '') {
            return 'application/octet-stream';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        try {
            $mimeType = finfo_buffer($finfo, $buffer);
        } finally {
            finfo_close($finfo);
        }

        return $mimeType !== false ? $mimeType : 'application/octet-stream';
    }
}
