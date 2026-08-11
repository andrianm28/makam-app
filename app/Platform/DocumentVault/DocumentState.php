<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault;

/**
 * The closed set of lifecycle states for a document in the vault.
 *
 * Values mirror the `documents.state` PostgreSQL CHECK constraint in
 * `2026_08_09_100000_create_documents_table.php`.
 */
enum DocumentState: string
{
    case Uploading = 'UPLOADING';
    case Quarantined = 'QUARANTINED';
    case Scanning = 'SCANNING';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
    case Expired = 'EXPIRED';
    case Deleted = 'DELETED';
}
