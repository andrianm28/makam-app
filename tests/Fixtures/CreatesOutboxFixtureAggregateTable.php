<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `outbox_fixture_aggregates` ad hoc, inside the test process,
 * instead of via a shipped `database/migrations/*.php` file — see
 * `OutboxFixtureAggregate`'s own doc block for why. `Schema::hasTable()`
 * guards the create the same way `CreatesScopedTestModelTable` does, for
 * the same reason: this project's `RefreshDatabase` trait keeps a single
 * persistent connection across the whole test run (wrapping each
 * individual test in a transaction it rolls back), so the table only needs
 * to be created once per run.
 */
trait CreatesOutboxFixtureAggregateTable
{
    protected function setUpOutboxFixtureAggregateTable(): void
    {
        if (Schema::hasTable('outbox_fixture_aggregates')) {
            return;
        }

        Schema::create('outbox_fixture_aggregates', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->timestamps();
        });
    }
}
