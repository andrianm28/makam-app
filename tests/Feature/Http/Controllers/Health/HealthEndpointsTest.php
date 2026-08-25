<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Health;

use App\Platform\Observability\ReadinessCheck;
use App\Platform\Observability\ReadinessResult;
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
