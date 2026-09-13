<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Health;

use App\Platform\Observability\ReadinessCheck;
use App\Platform\Observability\ReadinessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * `GET /health/live` and `GET /health/ready` — `ci-cd-and-release.md` §8's
 * required pair. Both are public and unauthenticated by design; neither
 * test asserts against restricted content because neither route may ever
 * emit any (`HealthReadyController`'s own doc block).
 */
final class HealthEndpointsTest extends TestCase
{
    public function test_health_live_returns_200_with_no_dependency(): void
    {
        $this->get('/health/live')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    /**
     * API-08: `/health/live` sits in `routes/web.php`, which inherits the
     * `web` middleware group (`bootstrap/app.php`'s
     * `web: __DIR__.'/../routes/web.php'`). That group's `StartSession`
     * (session driver defaults to `database`, `config/session.php`) and
     * the `throttle:public-guest` limiter (cache-backed,
     * `AppServiceProvider::boot()`) both talk to a downstream dependency
     * before any route's controller runs. Without `routes/web.php`'s
     * `->withoutMiddleware([...])` on this route, a session-store or cache
     * outage would throw inside that middleware and take the liveness
     * probe down with it — defeating the entire point of a liveness check,
     * which must only fail when this process cannot answer HTTP at all.
     *
     * This test proves the property by pointing both the session's
     * database connection and the cache's Redis connection at a host
     * nothing is listening on, then asserting the route still returns 200
     * with no exception escaping. A config override alone (e.g. swapping
     * to an `array` driver) would not reproduce the bug — it has to be an
     * actual unreachable connection so the underlying middleware really
     * throws, the way `PublicGuestThrottleTest`'s and `ReadinessCheck`'s
     * own doc blocks describe real dependency failures behaving.
     */
    public function test_health_live_returns_200_when_session_store_and_cache_are_unreachable(): void
    {
        config([
            'database.connections.health-live-test-unreachable' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'unreachable',
                'username' => 'unreachable',
                'password' => 'unreachable',
            ],
            'session.driver' => 'database',
            'session.connection' => 'health-live-test-unreachable',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1,
            'cache.default' => 'redis',
        ]);
        DB::purge('health-live-test-unreachable');
        Redis::purge('default');

        $this->get('/health/live')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    /**
     * Same dependency outage as above, but for `/health/ready` — proving
     * the outage produces `HealthReadyController`'s intended clean `503`
     * JSON (via `ReadinessCheck`'s own try/catch), not an unhandled
     * exception thrown by `StartSession`/`throttle:public-guest` before
     * the controller ever runs.
     */
    public function test_health_ready_returns_a_clean_503_when_session_store_and_cache_are_unreachable(): void
    {
        config([
            'database.connections.health-ready-test-unreachable' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'unreachable',
                'username' => 'unreachable',
                'password' => 'unreachable',
            ],
            'session.driver' => 'database',
            'session.connection' => 'health-ready-test-unreachable',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1,
            'cache.default' => 'redis',
        ]);
        DB::purge('health-ready-test-unreachable');
        Redis::purge('default');

        $this->app->instance(ReadinessCheck::class, new class extends ReadinessCheck
        {
            public function run(): ReadinessResult
            {
                return new ReadinessResult(database: false, redis: false);
            }
        });

        $response = $this->get('/health/ready');

        $response->assertStatus(503);
        $this->assertStringNotContainsString('Exception', $response->getContent());
        $this->assertStringNotContainsString('.php', $response->getContent());
    }

    public function test_health_ready_returns_200_when_the_readiness_check_passes(): void
    {
        $this->app->instance(ReadinessCheck::class, new class extends ReadinessCheck
        {
            public function run(): ReadinessResult
            {
                return new ReadinessResult(database: true, redis: true);
            }
        });

        $this->get('/health/ready')
            ->assertOk()
            ->assertJson(['ready' => true, 'checks' => ['database' => true, 'redis' => true]]);
    }

    public function test_health_ready_returns_503_when_any_dependency_fails(): void
    {
        $this->app->instance(ReadinessCheck::class, new class extends ReadinessCheck
        {
            public function run(): ReadinessResult
            {
                return new ReadinessResult(database: true, redis: false);
            }
        });

        $this->get('/health/ready')
            ->assertStatus(503)
            ->assertJson(['ready' => false, 'checks' => ['database' => true, 'redis' => false]]);
    }

    public function test_health_ready_never_leaks_exception_detail_in_its_response(): void
    {
        $this->app->instance(ReadinessCheck::class, new class extends ReadinessCheck
        {
            public function run(): ReadinessResult
            {
                return new ReadinessResult(database: false, redis: false);
            }
        });

        $response = $this->get('/health/ready');

        $response->assertStatus(503);
        $this->assertStringNotContainsString('Exception', $response->getContent());
        $this->assertStringNotContainsString('.php', $response->getContent());
    }
}
