<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Observability;

use App\Platform\Observability\ReadinessCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

/**
 * `ReadinessCheck` — see its own doc block for why exactly these two
 * dependencies, and why failure detail is never part of the result.
 */
final class ReadinessCheckTest extends TestCase
{
    /**
     * The real happy path: CI's `php` job runs a genuine Postgres and Redis
     * service (`.github/workflows/ci.yml`), so this exercises real
     * connections rather than mocks for the case that matters most.
     */
    public function test_it_reports_ready_when_the_database_and_redis_are_both_reachable(): void
    {
        $result = (new ReadinessCheck)->run();

        $this->assertTrue($result->database);
        $this->assertTrue($result->redis);
        $this->assertTrue($result->isReady());
    }

    public function test_it_reports_not_ready_and_reports_the_exception_when_the_database_is_unreachable(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new RuntimeException('connection refused'));

        $result = (new ReadinessCheck)->run();

        $this->assertFalse($result->database);
        $this->assertFalse($result->isReady());
    }

    public function test_it_reports_not_ready_when_redis_is_unreachable_but_the_database_is_fine(): void
    {
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('connection refused'));

        $result = (new ReadinessCheck)->run();

        $this->assertTrue($result->database, 'The database check must run independently of the Redis check.');
        $this->assertFalse($result->redis);
        $this->assertFalse($result->isReady());
    }

    public function test_a_redis_ping_returning_false_is_not_ready(): void
    {
        $connection = new class
        {
            public function ping(): bool
            {
                return false;
            }
        };

        Redis::shouldReceive('connection')->once()->andReturn($connection);

        $result = (new ReadinessCheck)->run();

        $this->assertFalse($result->redis);
    }

    public function test_to_array_carries_only_the_two_named_booleans(): void
    {
        $result = (new ReadinessCheck)->run();

        $this->assertSame(['database', 'redis'], array_keys($result->toArray()));
    }
}
