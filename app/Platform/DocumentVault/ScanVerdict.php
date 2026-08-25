<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault;

/**
 * The closed set of malware-scan outcomes recorded for a document.
 *
 * Values mirror the `document_scans.verdict` PostgreSQL CHECK constraint in
 * `2026_08_09_100010_create_document_scans_table.php`.
 */
enum ScanVerdict: string
{
    case Clean = 'CLEAN';
    case Infected = 'INFECTED';
    case Suspicious = 'SUSPICIOUS';
    case Error = 'ERROR';
}
