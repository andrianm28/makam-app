<?php

declare(strict_types=1);

namespace App\Platform\Observability\Providers;

use App\Platform\IdentityAccess\Contracts\IdentityAccessAdapter;
use App\Platform\IdentityAccess\Panel\AdminPanelAccessPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registered in `bootstrap/providers.php`, following the same
 * add-a-one-line-entry precedent every other `Platform/*` provider already
 * uses there (see e.g. `IdentityAccessServiceProvider`'s own doc block).
 *
 * Final-review C2/I3: both gates below used to be — or, for Horizon, were
 * entirely missing as — closures inline in a `config/*.php` file, which
 * either breaks `php artisan config:cache` (Pulse's `authorize` closure
 * could not survive `var_export()`) or, per real Pulse/Horizon convention,
 * was never a config-file key to begin with (neither package defines an
 * `authorize`/`viewHorizon` config key — both are `Gate::define()` calls
 * a host application registers itself; verified against the current
 * Laravel Pulse and Horizon docs before writing this).
 *
 * `Gate::define()` is consulted lazily, at authorization-check time, not
 * during this provider's own `boot()` — so unlike Sentry's `before_send`
 * (see `SentryEventScrubber`'s doc block), there is no service-provider
 * boot-ordering hazard here: it does not matter whether this provider
 * boots before or after Pulse's/Horizon's own provider.
 *
 * Both gates reuse the exact same `AdminPanelAccessPolicy` +
 * `IdentityAccessAdapter::resolveActorContext()` logic already governing
 * `/admin` (see `AdminPanelAccessPolicy`'s own class-level doc block for
 * the four PANEL_ROLES this admits) — operational dashboards are exactly
 * the same "can do back-office work" audience, not a separate concept.
 */
final class ObservabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $authorizeForAdminPanel = static function (?Authenticatable $user): bool {
            $actorContext = app(IdentityAccessAdapter::class)->resolveActorContext($user);

            return app(AdminPanelAccessPolicy::class)->allows($actorContext);
        };

        // AGENTS.md §Observability's "access-controlled" requirement (also
        // named directly by release-gates.md §H's Pulse box) — moved
        // verbatim out of config/pulse.php's former `authorize` closure,
        // which is not a real Pulse config key (see this class's doc
        // block) and could not survive config:cache regardless.
        Gate::define('viewPulse', $authorizeForAdminPanel);

        // I3: `/horizon` had no authorization gate registered anywhere in
        // the app (verified via grep — no `viewHorizon`, no
        // `Horizon::auth()`, no `HorizonServiceProvider`). This is that
        // gate — the actual authorization mechanism Horizon's own docs
        // document (`Gate::define('viewHorizon', ...)`), reusing the
        // identical admin-panel-access rule Pulse uses above.
        Gate::define('viewHorizon', $authorizeForAdminPanel);
    }
}
