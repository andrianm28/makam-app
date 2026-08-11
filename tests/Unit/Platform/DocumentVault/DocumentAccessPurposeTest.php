<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault;

use App\Platform\DocumentVault\DocumentAccessPurpose;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Task 2 deferred a Minor: the `document_access_events.purpose` /
 * `signed_url_grants.purpose` closed list existed only as a PostgreSQL CHECK
 * constraint with no PHP counterpart, so application code had to spell the
 * values as raw strings. `DocumentAccessPurpose` is that counterpart.
 *
 * The CHECK constraints are added on the pgsql driver only (SQLite cannot add
 * a constraint with `ALTER TABLE` and remains the local PHPUnit driver), so a
 * runtime "insert an invalid value and expect a violation" test would be
 * SKIPPED locally and could not protect this parity at all. This test instead
 * reads the migrations' own SQL source text, which is driver-independent — it
 * fails on both SQLite and PostgreSQL the moment either list drifts.
 */
final class DocumentAccessPurposeTest extends TestCase
{
    private const string ACCESS_EVENTS_MIGRATION =
        'database/migrations/2026_08_09_100020_create_document_access_events_table.php';

    private const string SIGNED_URL_GRANTS_MIGRATION =
        'database/migrations/2026_08_09_100030_create_signed_url_grants_table.php';

    public function test_enum_cases_match_the_document_access_events_purpose_check_list(): void
    {
        $this->assertSame(
            $this->purposeCheckListFrom(self::ACCESS_EVENTS_MIGRATION),
            array_map(
                static fn (DocumentAccessPurpose $purpose): string => $purpose->value,
                DocumentAccessPurpose::cases(),
            ),
        );
    }

    public function test_enum_cases_match_the_signed_url_grants_purpose_check_list(): void
    {
        $this->assertSame(
            $this->purposeCheckListFrom(self::SIGNED_URL_GRANTS_MIGRATION),
            array_map(
                static fn (DocumentAccessPurpose $purpose): string => $purpose->value,
                DocumentAccessPurpose::cases(),
            ),
        );
    }

    public function test_every_case_is_resolvable_from_its_stored_string(): void
    {
        foreach (DocumentAccessPurpose::cases() as $purpose) {
            $this->assertSame($purpose, DocumentAccessPurpose::from($purpose->value));
        }
    }

    /**
     * @return list<string>
     */
    private function purposeCheckListFrom(string $relativePath): array
    {
        $path = dirname(__DIR__, 4).'/'.$relativePath;
        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException("Unable to read migration [{$relativePath}].");
        }

        if (preg_match('/CHECK \(purpose IN \(([^)]*)\)\)/', $source, $matches) !== 1) {
            throw new RuntimeException("No purpose CHECK constraint found in [{$relativePath}].");
        }

        preg_match_all("/'([A-Z_]+)'/", $matches[1], $values);

        return $values[1];
    }
}
