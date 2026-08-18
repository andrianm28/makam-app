<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use Illuminate\Http\JsonResponse;

/**
 * `GET /health/live` — liveness only: this process can accept and answer
 * an HTTP request. No dependency is checked; a database or Redis outage
 * must NOT make this endpoint unhealthy, or an orchestrator would restart
 * a perfectly good application container to "fix" an outage restarting it
 * cannot fix. That distinction is the readiness check's job — see
 * `HealthReadyController`.
 *
 * A near-duplicate of the framework's own `/up`
 * (`bootstrap/app.php`'s `health: '/up'`), added under `/health/live`
 * specifically because `ci-cd-and-release.md` §8 names `/health/live` and
 * `/health/ready` as a pair by those exact paths, and a deploy/monitoring
 * script matching on that pair should not have to special-case one of them
 * living at a different URL.
 */
final class HealthLiveController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
