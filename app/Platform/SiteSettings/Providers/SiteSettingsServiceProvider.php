<?php

declare(strict_types=1);

namespace App\Platform\SiteSettings\Providers;

use App\Platform\SiteSettings\SettingsService;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the one container seam of this module. Registered in
 * `bootstrap/providers.php` on the same precedent every platform module
 * follows there (IdentityAccess, Correlation, Payment, DocumentVault,
 * FinancialLedger, Notification, Faq, OrderWorkflow): a provider nobody
 * registers is dead code, and `FeatureGateServiceProvider`'s own class
 * comment records that exact failure happening once in this codebase
 * already.
 *
 * `singleton()`, not `bind()`: `SettingsService` is the one query cache for
 * the module — its `$values` map is loaded from `site_settings` on the first
 * resolution that misses config and env, so a shared instance is exactly
 * what the doc block on `SettingsService` promises ("one query cache per
 * request"). A process-lifetime instance carries no actor state, so the
 * Horizon worker concern `FinancialLedgerServiceProvider`'s doc block
 * argues out for authorizer seams does not apply here.
 */
final class SiteSettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
    }
}
