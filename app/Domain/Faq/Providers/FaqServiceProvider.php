<?php

declare(strict_types=1);

namespace App\Domain\Faq\Providers;

use App\Domain\Faq\Authorization\RoleBasedFaqAuthorizer;
use App\Domain\Faq\Contracts\FaqAuthorizer;
use Illuminate\Support\ServiceProvider;

/**
 * Wires this module's one container seam. Registered in
 * `bootstrap/providers.php` on the six-way precedent
 * `AdminPanelProvider`/`FeatureGateServiceProvider`/
 * `IdentityAccessServiceProvider`/`CorrelationServiceProvider`/
 * `DocumentVaultServiceProvider`/`FinancialLedgerServiceProvider` already set
 * there: a provider nobody registers is dead code, and
 * `FeatureGateServiceProvider`'s own class comment records that exact failure
 * happening once in this codebase already — bindings existed, the provider was
 * never registered, and it only surfaced as a live 500.
 *
 * The binding is not optional and must not be made defensive. Every consumer
 * resolves `Contracts\FaqAuthorizer` from the container
 * (`Filament\Admin\Resources\FaqArticles\FaqArticleResource` and
 * `…\Tables\FaqArticlesTable`). If this line were removed, those calls would
 * raise `BindingResolutionException` — which is the correct failure mode for a
 * missing authorization seam. Nothing anywhere defaults to a permissive
 * authorizer when the binding is absent, so a misconfiguration can only ever
 * fail closed, never open.
 *
 * `bind()`, not `singleton()`/`scoped()`, for the reasons
 * `FinancialLedgerServiceProvider`'s doc block sets out at length:
 * `RoleBasedFaqAuthorizer` has no constructor and no state, judging the
 * `ActorContext` it is handed per call, so a shared instance buys nothing and a
 * process-lifetime one would establish the pattern that leaks one job's actor
 * into the next job's authorization decision inside a long-lived Horizon
 * worker.
 */
final class FaqServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FaqAuthorizer::class, RoleBasedFaqAuthorizer::class);
    }
}
