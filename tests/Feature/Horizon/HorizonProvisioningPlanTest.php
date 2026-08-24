<?php

declare(strict_types=1);

namespace Tests\Feature\Horizon;

use Laravel\Horizon\ProvisioningPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression lock for release-gates.md §H's Horizon rehearsal (24 Aug 2026),
 * which found `config/horizon.php` crashed `php artisan horizon` in every
 * environment: `minProcesses => 0` (invalid, Horizon requires >= 1) and
 * `'supervisor-X' => false` (array_replace_recursive collapses the nested
 * default array to a bare scalar, so SupervisorOptions::fromArray() then
 * reads a missing 'connection' key). ProvisioningPlan::get() eagerly parses
 * every environment in config('horizon.environments') at construction time,
 * so this is the exact call that threw before the fix.
 */
final class HorizonProvisioningPlanTest extends TestCase
{
    public static function environments(): array
    {
        return [
            'production' => ['production'],
            'staging' => ['staging'],
            'local' => ['local'],
        ];
    }

    #[DataProvider('environments')]
    public function test_the_provisioning_plan_constructs_without_throwing(string $environment): void
    {
        $plan = ProvisioningPlan::get('makam-horizon-test');

        $this->assertTrue($plan->hasEnvironment($environment));
    }
}
