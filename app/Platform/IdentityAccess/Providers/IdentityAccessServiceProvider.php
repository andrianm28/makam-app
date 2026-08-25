<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Providers;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\IdentityAccess\Adapters\LocalUsersTableIdentityAccessAdapter;
use App\Platform\IdentityAccess\Contracts\IdentityAccessAdapter;
use App\Platform\IdentityAccess\Listeners\RecordActorSessionOnLogin;
use App\Platform\IdentityAccess\Listeners\RecordActorSessionOnLogout;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\MasterDataAdminAuthorizer;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires this batch's `IdentityAccess` bindings and listeners.
 *
 * Registered in `bootstrap/providers.php`. That file is not in this batch's
 * literal owned-files list, but Batch 2.4 (S2-T3) already established the
 * pattern of adding a one-line registration entry there for a new provider
 * it introduced (`AdminPanelProvider::class`) — without the same here, this
 * entire file (and everything it binds) is dead code no consumer can reach.
 * Flagged explicitly in this batch's report rather than silently done.
 */
final class IdentityAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IdentityAccessAdapter::class, LocalUsersTableIdentityAccessAdapter::class);

        // Shared master-data administration gate — one binding gates every
        // master-data Filament resource (same shape as the FinancialLedger
        // provider's `bind(Contract::class, Implementation::class)` pattern).
        $this->app->bind(MasterDataAdminAuthorizerContract::class, MasterDataAdminAuthorizer::class);

        // scoped(), not singleton() — see ActorContextResolver's class-level
        // doc block for the exact reasoning (Horizon queue workers are a
        // real long-lived-process risk in this codebase even though there
        // is no Octane).
        $this->app->scoped(ActorContextResolver::class, function ($app): ActorContextResolver {
            return new ActorContextResolver(
                $app->make(IdentityAccessAdapter::class),
                $app->make(AuthFactory::class),
            );
        });

        // Convenience binding so consumers can type-hint `ActorContext`
        // directly via constructor injection instead of always going
        // through `ActorContextResolver::resolve()`. Still exactly one
        // resolution per request/job: this closure only runs on the first
        // `app(ActorContext::class)`/injection, because it is itself
        // `scoped()`, and it delegates to the same cached
        // `ActorContextResolver` instance either way.
        $this->app->scoped(ActorContext::class, function ($app): ActorContext {
            return $app->make(ActorContextResolver::class)->resolve();
        });
    }

    public function boot(): void
    {
        Event::listen(Login::class, RecordActorSessionOnLogin::class);
        Event::listen(Logout::class, RecordActorSessionOnLogout::class);
    }
}
