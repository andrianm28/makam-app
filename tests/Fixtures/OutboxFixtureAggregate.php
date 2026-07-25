<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Test-only fixture standing in for a real domain aggregate + mutation,
 * per S3-T11's batch brief: "`app/Domain/**` is empty scaffolding, and no
 * real business mutation (booking, payment, etc.) exists anywhere in this
 * repo yet. AC1's 'insert in the same transaction as the state mutation'
 * ... therefore cannot be proven against a real domain feature — prove it
 * against a minimal test-only fixture aggregate/mutation."
 *
 * Deliberately lives under `tests/Fixtures/`, not `app/**` — mirrors
 * `ScopedTestModel`'s own doc block reasoning exactly: never autoloaded in
 * production (PSR-4 `Tests\` maps to `tests/`, `autoload-dev` only), and its
 * backing table (`outbox_fixture_aggregates`) is created ad hoc by
 * `CreatesOutboxFixtureAggregateTable` inside test `setUp()`, not by a
 * shipped `database/migrations/*.php` file.
 */
final class OutboxFixtureAggregate extends Model
{
    protected $table = 'outbox_fixture_aggregates';

    /**
     * @var list<string>
     */
    protected $fillable = ['status'];
}
