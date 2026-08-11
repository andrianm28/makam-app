<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault;

/**
 * The closed set of purposes a restricted document may be reached for —
 * AC6's "single purpose" signed URL and AC8's "purpose" on every recorded
 * access.
 *
 * Values mirror the `purpose` PostgreSQL CHECK constraint that
 * `2026_08_09_100020_create_document_access_events_table.php` and
 * `2026_08_09_100030_create_signed_url_grants_table.php` both add. Task 2's
 * review deferred this enum as a Minor ("no PHP enum for the closed list —
 * revisit at Task 6"); this is that enum, and it is the single source of the
 * purpose strings for every writer in the module. Those CHECK constraints
 * are pgsql-only (SQLite cannot add a constraint with `ALTER TABLE` and
 * remains the local PHPUnit driver), so this enum — cast on both
 * `Models\DocumentAccessEvent` and `Models\SignedUrlGrant` — is the only
 * closed-list enforcement that runs on the local driver, exactly the role
 * `DocumentState`/`DocumentKind` already play for `documents`.
 * `Tests\Unit\Platform\DocumentVault\DocumentAccessPurposeTest` asserts the
 * two lists have not drifted, by reading the migrations' own SQL text rather
 * than the database, so the parity check runs on every driver.
 *
 * `Grant` is the purpose recorded for the ISSUANCE of a signed URL itself
 * (`Actions\IssueSignedUrl`), which is deliberately distinguishable in
 * `document_access_events` from the later redemption of that URL — the
 * redemption records the purpose the grant was scoped to (`View`/`Download`
 * /...). Without that distinction an auditor could not tell "a URL was
 * handed out" from "the bytes were actually served."
 */
enum DocumentAccessPurpose: string
{
    case View = 'VIEW';
    case Download = 'DOWNLOAD';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
    case Grant = 'GRANT';
}
