<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Exceptions;

use App\Platform\DocumentVault\DocumentKind;
use RuntimeException;

/**
 * Thrown by `DocumentValidator` when an upload fails size, extension, or
 * MIME verification (AC3). Every named constructor produces a message built
 * only from the document kind and a fixed reason phrase — never the
 * client-supplied original filename, the sniffed byte content, or any other
 * restricted data, per the module's "no document content, filename bytes,
 * or MIME/scanner detail in ... exception messages that reach users" rule.
 */
final class DocumentValidationException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly DocumentKind $kind,
    ) {
        parent::__construct($message);
    }

    public static function sizeExceeded(DocumentKind $kind): self
    {
        return new self(
            "The uploaded file exceeds the maximum size permitted for {$kind->value} documents.",
            'size_exceeded',
            $kind,
        );
    }

    public static function extensionNotAllowed(DocumentKind $kind): self
    {
        return new self(
            "The uploaded file's extension is not permitted for {$kind->value} documents.",
            'extension_not_allowed',
            $kind,
        );
    }

    public static function mimeNotAllowed(DocumentKind $kind): self
    {
        return new self(
            "The uploaded file's actual content type is not permitted for {$kind->value} documents.",
            'mime_not_allowed',
            $kind,
        );
    }

    public static function extensionMimeMismatch(DocumentKind $kind): self
    {
        return new self(
            "The uploaded file's extension does not match its actual content for {$kind->value} documents.",
            'extension_mime_mismatch',
            $kind,
        );
    }

    /**
     * A short, machine-checkable reason code for the rejection — the
     * "per-kind reason" the brief requires callers be able to surface
     * without parsing the free-text message.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    public function kind(): DocumentKind
    {
        return $this->kind;
    }
}
