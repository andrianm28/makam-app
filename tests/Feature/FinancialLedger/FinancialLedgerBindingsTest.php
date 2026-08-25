<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\FinancialLedger\Contracts\Journal as JournalContract;
use App\Platform\FinancialLedger\Contracts\LedgerReadAuthorizer;
use App\Platform\FinancialLedger\Contracts\PayoutAuthorizer;
use App\Platform\FinancialLedger\Contracts\PayoutProofVerifier;
use App\Platform\FinancialLedger\Contracts\ReconciliationAuthorizer;
use App\Platform\FinancialLedger\Contracts\VendorPayableAuthorizer;
use App\Platform\FinancialLedger\FinanceLedgerReadAuthorizer;
use App\Platform\FinancialLedger\FinanceOrRestrictedAdminPayoutAuthorizer;
use App\Platform\FinancialLedger\FinanceReconciliationAuthorizer;
use App\Platform\FinancialLedger\FinanceVendorPayableAuthorizer;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\Providers\FinancialLedgerServiceProvider;
use Tests\TestCase;

/**
 * Locks in `FinancialLedgerServiceProvider` — both the bindings it makes and
 * the one it deliberately does not. Same style as
 * `tests/Feature/Correlation/CorrelationServiceProviderBindingTest.php`.
 *
 * The provider registration in `bootstrap/providers.php` is as load-bearing as
 * the bindings themselves: `FeatureGateServiceProvider`'s own class comment
 * records a case in this codebase where the bindings existed, the registration
 * line did not, and the gap only surfaced as a live 500 on the first real HTTP
 * request. `test_the_provider_is_registered_in_the_application` is the
 * regression test for that, and every `make()` below would equally fail if the
 * registration were dropped.
 */
final class FinancialLedgerBindingsTest extends TestCase
{
    public function test_the_provider_is_registered_in_the_application(): void
    {
        $this->assertTrue(
            $this->app->providerIsLoaded(FinancialLedgerServiceProvider::class),
            'FinancialLedgerServiceProvider must stay listed in bootstrap/providers.php.',
        );
    }

    public function test_the_cross_lane_journal_seam_resolves_to_this_lanes_implementation(): void
    {
        $this->assertInstanceOf(Journal::class, $this->app->make(JournalContract::class));
    }

    public function test_every_authorizer_seam_resolves_to_its_finance_policy(): void
    {
        $this->assertInstanceOf(
            FinanceLedgerReadAuthorizer::class,
            $this->app->make(LedgerReadAuthorizer::class),
        );
        $this->assertInstanceOf(
            FinanceReconciliationAuthorizer::class,
            $this->app->make(ReconciliationAuthorizer::class),
        );
        $this->assertInstanceOf(
            FinanceVendorPayableAuthorizer::class,
            $this->app->make(VendorPayableAuthorizer::class),
        );
        $this->assertInstanceOf(
            FinanceOrRestrictedAdminPayoutAuthorizer::class,
            $this->app->make(PayoutAuthorizer::class),
        );
    }

    /**
     * The binding-style decision, pinned as behaviour rather than left in a
     * doc block.
     *
     * `bind()` is transient on purpose. `ActorContext` is `scoped()`, and the
     * framework resets scoped instances between HTTP requests AND between jobs
     * inside one long-lived Horizon worker process. `Actions\BulkFinancialExport`,
     * `Actions\ResolveException` and `Actions\ManualPayout` all take
     * `ActorContext` as a CONSTRUCTOR argument, so any shared instance in this
     * module would eventually attribute one actor's financial writes and audit
     * rows to another. Nothing bound here is expensive to build, so a shared
     * instance would buy nothing and cost that.
     *
     * If someone converts these to `singleton()` or `scoped()`, this test goes
     * red and they have to justify it in the diff.
     */
    public function test_the_seams_are_bound_transiently_so_no_instance_can_outlive_an_actor(): void
    {
        foreach ([
            JournalContract::class,
            LedgerReadAuthorizer::class,
            ReconciliationAuthorizer::class,
            PayoutAuthorizer::class,
            VendorPayableAuthorizer::class,
        ] as $seam) {
            $this->assertNotSame(
                $this->app->make($seam),
                $this->app->make($seam),
                sprintf('[%s] must be bound with bind(), not singleton()/scoped().', $seam),
            );
        }
    }

    /**
     * The security control, asserted as an absence.
     *
     * `RejectingPayoutProofVerifier` is the only implementation in the tree and
     * it refuses every reference until L1's DocumentVault adapter is wired at
     * merge integration. `Actions\ManualPayout` defaults its own constructor
     * argument to it, so payouts fail closed with or without a binding — but a
     * binding added here would be the single edit that could silently make the
     * seam permissive, so its absence is pinned.
     */
    public function test_the_payout_proof_verifier_is_left_unbound_and_fail_closed(): void
    {
        $this->assertFalse(
            $this->app->bound(PayoutProofVerifier::class),
            'PayoutProofVerifier must stay unbound until L1 supplies a real DocumentVault adapter.',
        );
    }
}
