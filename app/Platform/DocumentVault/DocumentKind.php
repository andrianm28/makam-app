<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault;

/**
 * The closed set of document kinds accepted by the platform document vault.
 *
 * Values mirror the `documents.document_kind` PostgreSQL CHECK constraint in
 * `2026_08_09_100000_create_documents_table.php`.
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
}
