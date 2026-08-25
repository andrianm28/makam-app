<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Providers;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Authorization\OrderTransitionAuthorizer;
use Illuminate\Support\ServiceProvider;

/**
 * Wires this module's one container seam. Registered in
 * `bootstrap/providers.php` on the seven-way precedent `AdminPanelProvider`/
 * `FeatureGateServiceProvider`/`IdentityAccessServiceProvider`/
 * `CorrelationServiceProvider`/`DocumentVaultServiceProvider`/
 * `FinancialLedgerServiceProvider`/`FaqServiceProvider` already set there: a
 * provider nobody registers is dead code, and `FeatureGateServiceProvider`'s
 * own class comment records that exact failure happening once in this
 * codebase already — bindings existed, the provider was never registered,
 * and it only surfaced as a live 500.
 *
 * The binding is not optional and must not be made defensive. Every consumer
 * resolves `Contracts\OrderTransitionAuthorizerContract` from the container
 * (`TransitionOrderAction`, the marketplace/renewal header actions, and the
 * resource access checks). If this line were removed, those calls would
 * raise `BindingResolutionException` — which is the correct failure mode for
 * a missing authorization seam. Nothing anywhere defaults to a permissive
 * authorizer when the binding is absent, so a misconfiguration can only ever
 * fail closed, never open.
 *
 * `bind()`, not `singleton()`/`scoped()`, for the same reasons
 * `FaqServiceProvider`'s doc block sets out: `OrderTransitionAuthorizer` has
 * no constructor and no state, judging the `ActorContext` it is handed per
 * call, so a shared instance buys nothing and a process-lifetime one would
 * establish the pattern that leaks one job's actor into the next job's
 * authorization decision inside a long-lived Horizon worker.
 */
final class OrderWorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderTransitionAuthorizerContract::class, OrderTransitionAuthorizer::class);
    }
}
