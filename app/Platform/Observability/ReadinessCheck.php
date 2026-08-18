<?php

declare(strict_types=1);

namespace App\Platform\Observability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The dependency checks behind `GET /health/ready`
 * (`Http\Controllers\Health\HealthReadyController`) — see that controller's
 * doc block for why this is `/health/ready`, not `/up`
 * (`bootstrap/app.php`'s `health: '/up'`), and for what this deliberately
 * does NOT check.
 *
 * Two checks only, both genuinely load-bearing for "can this instance serve
 * a request right now": the primary database and the queue/session/cache
 * connection. Neither check's failure detail is ever returned to a caller
 * — this endpoint is public and unauthenticated (`ci-cd-and-release.md`
 * §8 requires it reachable for a deploy's own health check before any
 * traffic is routed), so a stack trace or connection string here would be
 * a reconnaissance gift. `report()` still records the real exception
 * server-side for whoever is watching logs/error tracking.
 *
 * Not `final`: `Http\Controllers\Health\HealthReadyController` type-hints
 * this concrete class directly (there is exactly one real implementation,
 * so a full `Contracts\*` interface seam is not warranted for this), and
 * `HealthEndpointsTest` swaps in an anonymous subclass to test the
 * controller's 200/503 branching without needing to fake a database or
 * Redis outage at the HTTP-test layer.
 */
class ReadinessCheck
{
    public function run(): ReadinessResult
    {
        return new ReadinessResult(
            database: $this->checkDatabase(),
            redis: $this->checkRedis(),
        );
    }

    private function checkDatabase(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            return Redis::connection()->ping() !== false;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
