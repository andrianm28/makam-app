<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERF-13 (Phase 3 Batch M7a). `2026_08_08_100000_create_grave_records_
 * table.php` already added `grave_records_name_trgm_idx`, a GIN index on
 * `deceased_name_normalized` — but that index's own doc block correctly
 * says it does NOT accelerate `ORDER BY similarity(...)`: GIN has no
 * ordering support at all, only a GiST index does, via the KNN distance
 * operator `<->`.
 *
 * `GraveRegistryPublicQuery::buildQuery()` is rewritten alongside this
 * migration to order by `deceased_name_normalized <-> ?` instead of
 * `similarity(deceased_name_normalized, ?) DESC` specifically so this GiST
 * index can serve that ORDER BY as an index scan instead of a sort over a
 * full filtered result set. The GIN index is kept, not replaced — it still
 * carries the WHERE clause's `%`/LIKE matching (`gin_trgm_ops` indexes
 * those operators; `gist_trgm_ops` also supports them, but GIN remains the
 * better choice for pure equality/prefix-style trigram lookups per
 * PostgreSQL's own documentation, and this repository's convention is not
 * to remove a working index without a measured reason to).
 *
 * Guarded to `pgsql` only, matching every other driver-specific statement
 * against this table (`grave_records_name_trgm_idx` itself, `pg_trgm`'s
 * `CREATE EXTENSION`) — see that migration's own doc block for why SQLite
 * (this repo's default local test driver) is exempt rather than degraded.
 *
 * Non-destructive, additive-only change against an already-deployed table
 * (`AGENTS.md` §Database: expand/contract) — an index add, unlike a
 * destructive migration, needs no human sign-off under
 * `AGENTS.md` §Infrastructure-agent execution.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // pg_trgm is already created by the grave_records migration (same
        // database-scoped extension); CONCURRENTLY is not used here because
        // Laravel migrations run inside a transaction by default and
        // CREATE INDEX CONCURRENTLY cannot run inside one — the same
        // constraint the sibling GIN index's migration accepted.
        DB::statement(
            'CREATE INDEX grave_records_name_trgm_gist_idx ON grave_records USING gist (deceased_name_normalized gist_trgm_ops)'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS grave_records_name_trgm_gist_idx');
        }
    }
};
