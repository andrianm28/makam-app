<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `scoped_test_models` ad hoc, inside the test process, instead of
 * via a shipped `database/migrations/*.php` file — see
 * `ScopedTestModel`'s own doc block for why. `Schema::hasTable()` guards
 * the create because this project's `RefreshDatabase` trait keeps a single
 * persistent SQLite `:memory:` connection across the whole test run
 * (wrapping each individual test in a transaction it rolls back), so the
 * table only needs to be created once per run, not once per test method.
 */
trait CreatesScopedTestModelTable
{
    protected function setUpScopedTestModelTable(): void
    {
        if (Schema::hasTable('scoped_test_models')) {
            return;
        }

        Schema::create('scoped_test_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
