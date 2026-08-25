<?php

declare(strict_types=1);

namespace App\Platform\Audit\Providers;

use App\Platform\Audit\Contracts\AuditReadAuthorizer;
use App\Platform\Audit\RoleBasedAuditReadAuthorizer;
use Illuminate\Support\ServiceProvider;

/**
 * Wires this module's read-authorization seam. Registered in
 * `bootstrap/providers.php` — that file is not this task's literal
 * owned-files list, but the same precedent every other provider in that file
 * already documents (`FinancialLedgerServiceProvider`,
 * `DocumentVaultServiceProvider`, `FaqServiceProvider`, …) applies here:
 * without this binding, `app(Contracts\AuditReadAuthorizer::class)` raises
 * `BindingResolutionException` on the first admin request to the audit
 * review resource, which is the correct fail-closed behaviour for a missing
 * authorization seam, but the binding still needs registering somewhere for
 * the resource to ever resolve at all.
 *
 * The write-side (`Audit::record()`/`Audit::wrap()`) is deliberately a
 * plain static-method class with no container binding at all — see that
 * class's own doc block. This provider governs only the READ side this
 * batch adds; it does not change that decision.
 *
 * `bind()`, not `singleton()`: `RoleBasedAuditReadAuthorizer` is stateless
 * (no constructor, `ActorContext` taken as a method parameter, freshly, per
 * call) and costs nothing to construct — same reasoning
 * `FinancialLedgerServiceProvider` gives for its own four authorizer
 * bindings, including the stale-actor-in-a-long-lived-worker hazard a
 * `singleton()` would risk if a future consumer ever captured the actor in
 * a constructor.
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditReadAuthorizer::class, RoleBasedAuditReadAuthorizer::class);
    }
}
