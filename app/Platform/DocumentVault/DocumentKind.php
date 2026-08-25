<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault;

/**
 * The closed set of document kinds accepted by the platform document vault.
 *
 * Values mirror the `documents.document_kind` PostgreSQL CHECK constraint in
 * `2026_08_09_100000_create_documents_table.php`. Do not add, remove, or
 * rename a case, and never change a case's string value — every value here
 * is byte-for-byte load-bearing against that CHECK constraint.
 *
 * The per-kind methods below (`maxSizeBytes()`, `allowedExtensions()`,
 * `allowedMimeTypes()`, `scannerRequired()`) are Task 3's single source of
 * truth for validation limits, read by both `DocumentValidator` (extension
 * allowlist, size cap, MIME allowlist) and `Adapters\MockScanner` (size cap
 * for the SUSPICIOUS verdict), so the two never duplicate this table
 * (`task-3-brief.md` ambiguity ruling 2). None of these limits are named by
 * `file-upload-pipeline.md` or the plan as exact figures ("identity docs
 * small cap; grave import larger cap" is the only guidance given); the
 * concrete byte/extension/MIME values below are this task's judgement call,
 * flagged for review rather than treated as canonical policy.
 */
enum DocumentKind: string
{
    case Ktp = 'KTP';
    case Kk = 'KK';
    case DeathCertificate = 'DEATH_CERTIFICATE';
    case PaymentProof = 'PAYMENT_PROOF';
    case Agreement = 'AGREEMENT';
    case Certificate = 'CERTIFICATE';
    case VendorEvidence = 'VENDOR_EVIDENCE';
    case ProductImage = 'PRODUCT_IMAGE';
    case GraveImport = 'GRAVE_IMPORT';

    /**
     * Maximum accepted upload size in bytes for this kind.
     *
     * Identity and payment documents carry a small cap (photographs or
     * single-page scans/PDFs); legal documents a moderate cap; the grave
     * registry import carries the deliberately larger cap the brief calls
     * out (`file-upload-pipeline.md` §1 import files; bulk CSV/XLSX rows).
     */
    public function maxSizeBytes(): int
    {
        return match ($this) {
            self::Ktp, self::Kk, self::DeathCertificate, self::PaymentProof => 5 * 1024 * 1024,
            self::Agreement, self::Certificate => 10 * 1024 * 1024,
            self::VendorEvidence, self::ProductImage => 10 * 1024 * 1024,
            self::GraveImport => 50 * 1024 * 1024,
        };
    }

    /**
     * Lower-case, dot-free extension allowlist for this kind.
     *
     * Identity documents never allow executable/script formats
     * (`file-upload-pipeline.md` §5); every kind here is restricted to the
     * narrow image/document/import formats it actually needs.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        return match ($this) {
            self::Ktp, self::Kk, self::DeathCertificate, self::PaymentProof => ['jpg', 'jpeg', 'png', 'pdf'],
            self::Agreement, self::Certificate => ['pdf'],
            self::VendorEvidence, self::ProductImage => ['jpg', 'jpeg', 'png'],
            self::GraveImport => ['csv', 'xlsx'],
        };
    }

    /**
     * Actual (finfo-sniffed) MIME type allowlist for this kind.
     *
     * `DocumentValidator` checks the sniffed content type against this list
     * — never the client-declared type — so a spoofed extension whose real
     * content is outside this list is rejected regardless of what the
     * filename claims.
     *
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::Ktp, self::Kk, self::DeathCertificate, self::PaymentProof => [
                'image/jpeg', 'image/png', 'application/pdf',
            ],
            self::Agreement, self::Certificate => ['application/pdf'],
            self::VendorEvidence, self::ProductImage => ['image/jpeg', 'image/png'],
            self::GraveImport => [
                'text/csv', 'text/plain',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
            ],
        };
    }

    /**
     * Whether documents of this kind must pass a malware scan before
     * promotion. Every kind in the vault is scanner-required today (the
     * `documents.scanner_required` column default matches); this method
     * exists so a future kind-specific exemption is a one-line change here
     * rather than a new duplicated table (ambiguity ruling 2).
     */
    public function scannerRequired(): bool
    {
        return true;
    }
}
