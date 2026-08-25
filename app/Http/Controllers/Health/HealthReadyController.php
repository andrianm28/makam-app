<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use App\Platform\Observability\ReadinessCheck;
use Illuminate\Http\JsonResponse;

/**
 * `GET /health/ready` — can this instance actually serve a request right
 * now, not just "is the process alive" (`HealthLiveController`/`/up`).
 * `ci-cd-and-release.md` §8 requires this pair before a deploy routes
 * traffic to a new container.
 *
 * Public and unauthenticated by necessity (a deploy script and an uptime
 * monitor both need to reach it with no credential), so the response is
 * deliberately minimal: which named check failed, never why — no
 * exception message, no connection string, no stack trace. `AGENTS.md`
 * §Observability's "never place restricted data in logs" spirit extends
 * here to "never place infrastructure detail in a public response";
 * `ReadinessCheck` still calls `report()` on every failure, so the real
 * detail reaches whoever is watching server-side error tracking.
 *
 * 503, not 200-with-a-false-flag, on any failing check — this must be
 * machine-readable by a deploy script or load balancer health probe
 * without it having to parse the JSON body to learn the outcome.
 */
final class HealthReadyController
{
    public function __construct(private readonly ReadinessCheck $check) {}

    public function __invoke(): JsonResponse
    {
        $result = $this->check->run();

        return response()->json(
            ['ready' => $result->isReady(), 'checks' => $result->toArray()],
            $result->isReady() ? 200 : 503,
        );
    }
}
