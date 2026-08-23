<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The one real, previously-uncovered gap `docs/testing/release-gates.md`
 * §G named before this test existed: "migration (rollback-specific) — no
 * dedicated migration-rollback test found." Tests
 * `2026_08_22_100000_fix_customer_and_uploader_identity_columns.php`'s
 * `down()` in isolation, using direct instantiation instead of
 * `Artisan::call('migrate:rollback')` (dozens of later migrations would
 * make `--step=1` undo the wrong one), and seeding no row first (the
 * migrated tables have never held real data — confirmed in the
 * migration's own doc block — so this test proves the schema reversal,
 * not data preservation).
 *
 * Postgres DDL is transactional, and `RefreshDatabase` wraps each test in
 * a transaction — so `down()`'s real `dropConstrainedForeignId()`/
 * `uuid()` calls below are automatically rolled back at the end of this
 * test, same as any other write. `up()` is called again at the end purely
 * so the test leaves the schema in the same state it found it in, as a
 * defensive measure in case that transactional-DDL assumption is ever
 * wrong on a future Postgres version.
 */
final class FixCustomerAndUploaderIdentityColumnsRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION_PATH = 'database/migrations/2026_08_22_100000_fix_customer_and_uploader_identity_columns.php';

    /**
     * @return array<int, array{string, string}>
     */
    public static function identityColumns(): array
    {
        return [
            ['subscriptions', 'customer_id'],
            ['service_acceptances', 'customer_id'],
            ['service_complaints', 'customer_id'],
            ['work_evidence', 'uploaded_by'],
        ];
    }

    #[DataProvider('identityColumns')]
    public function test_down_reverts_the_column_to_a_bare_uuid_with_no_foreign_key(string $table, string $column): void
    {
        $migration = $this->loadMigration();

        self::assertSame(
            'bigint',
            $this->columnDataType($table, $column),
            "{$table}.{$column} should be bigint (the foreignId shape) before down() runs"
        );
        self::assertTrue(
            $this->hasForeignKey($table, $column),
            "{$table}.{$column} should have a foreign key constraint before down() runs"
        );

        $migration->down();

        self::assertSame(
            'uuid',
            $this->columnDataType($table, $column),
            "{$table}.{$column} should revert to uuid after down()"
        );
        self::assertFalse(
            $this->hasForeignKey($table, $column),
            "{$table}.{$column} should have no foreign key constraint after down()"
        );

        // Leave the schema as RefreshDatabase's next test expects it —
        // defensive, see this class's own doc block.
        $migration->up();

        self::assertSame(
            'bigint',
            $this->columnDataType($table, $column),
            "{$table}.{$column} should be back to bigint after re-running up()"
        );
    }

    private function loadMigration(): Migration
    {
        return require base_path(self::MIGRATION_PATH);
    }

    private function columnDataType(string $table, string $column): string
    {
        $row = DB::selectOne(
            'SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$table, $column]
        );

        self::assertNotNull($row, "Column {$table}.{$column} was not found in information_schema");

        return $row->data_type;
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        $row = DB::selectOne(
            <<<'SQL'
                SELECT COUNT(*) AS count
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name
                WHERE tc.constraint_type = 'FOREIGN KEY'
                    AND tc.table_name = ?
                    AND kcu.column_name = ?
                SQL,
            [$table, $column]
        );

        return ((int) $row->count) > 0;
    }
}
