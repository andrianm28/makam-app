<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Providers;

use App\Platform\FinancialLedger\Contracts\Journal as JournalContract;
use App\Platform\FinancialLedger\Contracts\LedgerReadAuthorizer;
use App\Platform\FinancialLedger\Contracts\PayoutAuthorizer;
use App\Platform\FinancialLedger\Contracts\ReconciliationAuthorizer;
use App\Platform\FinancialLedger\FinanceLedgerReadAuthorizer;
use App\Platform\FinancialLedger\FinanceOrRestrictedAdminPayoutAuthorizer;
use App\Platform\FinancialLedger\FinanceReconciliationAuthorizer;
use App\Platform\FinancialLedger\Journal;
use Illuminate\Support\ServiceProvider;

/**
 * Wires this module's container seams. Registered in `bootstrap/providers.php`
 * — that file is not in this task's literal owned-files list, but Task 7's
 * brief explicitly authorizes the one additive line, on the four-way precedent
 * `AdminPanelProvider`/`FeatureGateServiceProvider`/`IdentityAccessServiceProvider`/
 * `CorrelationServiceProvider`/`DocumentVaultServiceProvider` already set there.
 *
 * Without this provider `app(Contracts\Journal::class)` does not resolve at all
 * (`Journal.php`'s own class doc block has recorded that deferral since Task 3),
 * so `lane/l3-payment-adapter` — which consumes the interface, not the concrete
 * class — would take a `BindingResolutionException` on its first real request.
 * `FeatureGateServiceProvider`'s class comment records that exact failure
 * happening once already in this codebase: bindings existed, the provider was
 * never registered, and it only surfaced as a live 500.
 *
 * ---------------------------------------------------------------------------
 * Why `bind()` and not `singleton()` or `scoped()`
 * ---------------------------------------------------------------------------
 * `bind()` is transient: the container builds a fresh instance per resolution.
 * That is the deliberate choice here, for three reasons, and it is a
 * correctness question in this module rather than a style one.
 *
 *  1. **`singleton()` is a latent stale-actor bug in a queue worker.**
 *     `ActorContext` is bound `scoped()` (see
 *     `IdentityAccessServiceProvider`), which means the framework resets it
 *     between HTTP requests AND between queued jobs in a long-lived Horizon
 *     worker process. Anything holding an `ActorContext` in a *constructor*
 *     property must therefore be rebuilt just as often.
 *     `Actions\BulkFinancialExport`, `Actions\ResolveException` and
 *     `Actions\ManualPayout` all take `ActorContext` as their first
 *     constructor argument. None of them is bound here — but a `singleton()`
 *     on this module's seams would establish exactly the pattern that breaks
 *     the moment one of them is, and it would break silently: a
 *     process-lifetime instance keeps job #1's actor and attributes job #2's
 *     financial writes and audit rows to them. In this module that is a
 *     security defect, not a caching wrinkle.
 *  2. **Nothing bound here is expensive or stateful enough to want sharing.**
 *     `Journal` holds one stateless `readonly PostJournalBatch`; the three
 *     authorizers have no constructor and no state at all, taking the
 *     `ActorContext` they judge as a *method* parameter, freshly, per call.
 *     Construction is a `new` with no I/O, so a shared instance buys nothing
 *     measurable and costs the hazard in (1).
 *  3. **`scoped()` would be safe today but says something untrue.**
 *     `scoped()` means "this object caches per-request state that must not
 *     leak into the next request" — the reason `ActorContext` and
 *     `CorrelationContext` use it. None of these four caches anything. Using
 *     it here would imply a request-lifetime identity these objects do not
 *     have, and it would still mask hazard (1) inside a single request rather
 *     than removing it.
 *
 * `Journal` itself reads `ActorContext` through `app(ActorContext::class)` at
 * call time (`Journal::postReversal()`), not through its constructor, so it
 * would in fact survive a `singleton()`. That is a property of today's
 * implementation, not a contract term of `Contracts\Journal`, and it is not
 * worth betting the audit trail on.
 *
 * ---------------------------------------------------------------------------
 * `Contracts\PayoutProofVerifier` is deliberately NOT bound
 * ---------------------------------------------------------------------------
 * There is exactly one implementation in the tree, `RejectingPayoutProofVerifier`
 * (verified by grep over `app/` — the DocumentVault module that landed in the
 * Wave 1 merge exposes no payout-proof adapter), and it rejects every reference
 * by design until L1's DocumentVault adapter is wired at merge integration.
 * `Actions\ManualPayout` already defaults that constructor argument to
 * `new RejectingPayoutProofVerifier`, so the container falling through to the
 * default keeps the fail-closed behaviour with or without a binding. Binding it
 * to anything permissive here would break a security control; binding it to the
 * rejecting implementation would only make the eventual real adapter look like
 * a swap of an existing decision rather than the sign-off-requiring change it
 * is. Left unbound on purpose.
 *
 * `LedgerReport` is likewise unbound: it is a concrete query class with no
 * interface, autowires from an empty constructor, and its unscoped-read shape
 * (`summary($period, null)`) is finding N3 in the lane's merge sign-off bundle,
 * still open. Wrapping it in an authorizer-backed read seam is a signature
 * change across its call sites and is not this task's step.
 */
final class FinancialLedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The cross-lane seam. `lane/l3-payment-adapter` posts through this
        // interface; this line is what makes that resolvable.
        $this->app->bind(JournalContract::class, Journal::class);

        $this->app->bind(LedgerReadAuthorizer::class, FinanceLedgerReadAuthorizer::class);
        $this->app->bind(ReconciliationAuthorizer::class, FinanceReconciliationAuthorizer::class);
        $this->app->bind(PayoutAuthorizer::class, FinanceOrRestrictedAdminPayoutAuthorizer::class);

        // `Contracts\PayoutProofVerifier` is intentionally absent from this
        // list and must stay absent until L1's DocumentVault adapter exists —
        // see this class's doc block for why binding it either way today is
        // wrong. `tests/Feature/FinancialLedger/FinancialLedgerBindingsTest.php`
        // asserts it stays unbound, so the omission cannot be "fixed" silently.
    }
}
