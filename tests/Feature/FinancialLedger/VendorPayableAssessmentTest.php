<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\FinancialLedger\Actions\VendorPayable;
use App\Platform\FinancialLedger\Contracts\Journal as JournalContract;
use App\Platform\FinancialLedger\Exceptions\InvalidVendorPayableException;
use App\Platform\FinancialLedger\JournalReversalKind;
use App\Platform\FinancialLedger\Models\JournalBatch;
use App\Platform\FinancialLedger\Models\VendorPayable as VendorPayableModel;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\VendorPayableEligibility;
use App\Platform\FinancialLedger\VendorPayableState;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AC8: "THE SYSTEM SHALL determine vendor payable eligibility explicitly; a
 * paid state SHALL NOT imply payable, and a payable state SHALL NOT imply
 * paid out."
 *
 * The first test in this file is the one this whole task exists to protect.
 * `AGENTS.md` §Marketplace states it in four words — "Paid does not mean
 * completed" — and the defect it guards against is a condition somewhere that
 * reads "the customer paid, therefore we owe the vendor." Being paid is one of
 * three independent conditions, never a shortcut past the other two.
 *
 * Every assertion below re-reads the persisted row through the query builder
 * rather than trusting the model instance the Action handed back, so what is
 * asserted is what the database actually committed.
 */
final class VendorPayableAssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_paid_order_with_no_fulfilment_evidence_stays_held(): void
    {
        $payable = $this->assess(new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: false,
            disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
        ));

        $row = $this->persistedRow($payable);

        $this->assertSame(VendorPayableState::HELD, $row->state);
        $this->assertNull($row->eligible_at);
        $this->assertNull($row->paid_at);
    }

    public function test_a_paid_order_inside_its_dispute_window_stays_held_even_with_accepted_evidence(): void
    {
        $payable = $this->assess(new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: true,
            disputeWindowEndsAt: CarbonImmutable::now()->addDay(),
        ));

        $this->assertSame(VendorPayableState::HELD, $this->persistedRow($payable)->state);
    }

    public function test_an_absent_dispute_window_is_treated_as_not_elapsed(): void
    {
        // Fail closed: "we have no record of a dispute window" must never read
        // as "the dispute window is over."
        $payable = $this->assess(new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: true,
            disputeWindowEndsAt: null,
        ));

        $this->assertSame(VendorPayableState::HELD, $this->persistedRow($payable)->state);
    }

    public function test_unpaid_work_with_accepted_evidence_and_an_elapsed_window_stays_held(): void
    {
        $payable = $this->assess(new VendorPayableEligibility(
            orderPaid: false,
            fulfilmentEvidenceAccepted: true,
            disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
        ));

        $this->assertSame(VendorPayableState::HELD, $this->persistedRow($payable)->state);
    }

    public function test_evidence_accepted_plus_elapsed_dispute_window_plus_paid_becomes_payable(): void
    {
        $payable = $this->assess($this->eligible());

        $row = $this->persistedRow($payable);

        $this->assertSame(VendorPayableState::PAYABLE, $row->state);
        $this->assertNotNull($row->eligible_at);
        $this->assertNull($row->paid_at);
        $this->assertSame(250_000, (int) $row->amount_minor);
        $this->assertSame('badan-usaha-1', $row->entity_ref);
        $this->assertSame('vendor-1', $row->vendor_id);
    }

    public function test_eligible_assessment_accrues_dr_cost_and_cr_vendor_liability_once(): void
    {
        $payable = $this->assess($this->eligible());

        $batch = JournalBatch::query()
            ->where('business_key', 'vendor_payable:vendor-1:marketplace_order:order-1')
            ->sole();
        $entries = $batch->entries()->orderBy('account_code')->get();

        $this->assertSame(['2100', '5000'], $entries->pluck('account_code')->all());
        $this->assertSame(['CR', 'DR'], $entries->pluck('direction')->all());
        $this->assertSame([250_000, 250_000], $entries->pluck('amount_minor')->all());

        $this->assess($this->eligible());

        $this->assertSame(
            1,
            JournalBatch::query()
                ->where('business_key', 'vendor_payable:vendor-1:marketplace_order:order-1')
                ->count(),
        );
        $this->assertSame($payable->id, VendorPayableModel::query()->sole()->id);
    }

    public function test_accrual_failure_rolls_back_the_payable_and_audit_together(): void
    {
        $action = new VendorPayable(journal: new ThrowingJournal);

        try {
            $action->assess(
                vendorId: 'vendor-1',
                entityRef: 'badan-usaha-1',
                sourceType: 'marketplace_order',
                sourceId: 'order-rollback',
                amount: new Money(250_000),
                eligibility: $this->eligible(),
            );
            $this->fail('Expected the accrual journal failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('journal fixture failure', $exception->getMessage());
        }

        $this->assertSame(0, VendorPayableModel::query()->count());
        $this->assertSame(0, JournalBatch::query()->count());
        $this->assertSame(
            0,
            DB::table('audit_events')->where('action', VendorPayable::AUDIT_ACTION_ASSESSED)->count(),
        );
    }

    public function test_vendor_liability_account_is_seeded_additively(): void
    {
        $account = DB::table('coa_accounts')->where('code', '2100')->sole();

        $this->assertSame('Liabilitas — Utang Vendor', $account->name);
        $this->assertSame('CR', $account->normal_balance);
    }

    public function test_a_payable_row_is_not_paid_out(): void
    {
        $payable = $this->assess($this->eligible());

        // AC8's second half. "Payable" is what we owe; "paid out" is what we
        // sent. A payable row on its own is proof of the first and evidence of
        // nothing about the second.
        $this->assertSame(VendorPayableState::PAYABLE, $this->persistedRow($payable)->state);
        $this->assertSame(0, DB::table('payouts')->count());
        $this->assertFalse(VendorPayableModel::query()->findOrFail($payable->id)->isPaidOut());
        $this->assertSame(
            0,
            VendorPayableModel::query()->where('state', VendorPayableState::PAID)->count(),
        );
    }

    public function test_re_assessment_updates_the_existing_row_rather_than_creating_a_second_one(): void
    {
        $held = $this->assess(new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: false,
            disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
        ));

        $reassessed = $this->assess($this->eligible());

        $this->assertSame($held->id, $reassessed->id);
        $this->assertSame(1, VendorPayableModel::query()->count());
        $this->assertSame(VendorPayableState::PAYABLE, $this->persistedRow($reassessed)->state);
    }

    public function test_a_payable_never_falls_back_to_held_once_it_is_eligible(): void
    {
        $payable = $this->assess($this->eligible());

        // Evidence being withdrawn later is a dispute or a correction, not a
        // quiet downgrade of a debt we already recognised. Re-assessing with
        // failing inputs leaves the row where it is.
        $reassessed = $this->assess(new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: false,
            disputeWindowEndsAt: null,
        ));

        $this->assertSame($payable->id, $reassessed->id);
        $this->assertSame(VendorPayableState::PAYABLE, $this->persistedRow($reassessed)->state);
    }

    public function test_assessment_rejects_a_non_integer_amount(): void
    {
        // AC11: money is integer minor units end to end. A weak caller passing
        // a decimal string must not be able to truncate its way into a payable.
        //
        // Invoked through the container rather than as a direct call, so this
        // exercises the real runtime boundary the way an untyped caller (a
        // queued job payload, a Filament form, a Livewire property) reaches it
        // — a direct call would be rejected by static analysis before it ever
        // ran, which proves nothing about runtime.
        $this->expectException(\TypeError::class);

        $this->app->call([$this->action(), 'assess'], [
            'vendorId' => 'vendor-1',
            'entityRef' => 'badan-usaha-1',
            'sourceType' => 'marketplace_order',
            'sourceId' => 'order-1',
            'amount' => '250000',
            'eligibility' => $this->eligible(),
        ]);
    }

    public function test_assessment_rejects_a_negative_amount(): void
    {
        $this->expectException(InvalidVendorPayableException::class);

        $this->action()->assess(
            vendorId: 'vendor-1',
            entityRef: 'badan-usaha-1',
            sourceType: 'marketplace_order',
            sourceId: 'order-1',
            amount: new Money(-1),
            eligibility: $this->eligible(),
        );
    }

    public function test_the_database_refuses_a_state_outside_the_closed_list(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'The vendor_payables_state_check CHECK constraint is PostgreSQL-only; run with DB_CONNECTION=pgsql.'
            );
        }

        $payable = $this->assess($this->eligible());

        $this->expectException(QueryException::class);

        DB::table('vendor_payables')->where('id', $payable->id)->update(['state' => 'settled']);
    }

    private function eligible(): VendorPayableEligibility
    {
        return new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: true,
            disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
        );
    }

    private function assess(VendorPayableEligibility $eligibility): VendorPayableModel
    {
        return $this->action()->assess(
            vendorId: 'vendor-1',
            entityRef: 'badan-usaha-1',
            sourceType: 'marketplace_order',
            sourceId: 'order-1',
            amount: new Money(250_000),
            eligibility: $eligibility,
        );
    }

    private function persistedRow(VendorPayableModel $payable): object
    {
        return DB::table('vendor_payables')->where('id', $payable->id)->sole();
    }

    private function action(): VendorPayable
    {
        return $this->app->make(VendorPayable::class);
    }
}

final class ThrowingJournal implements JournalContract
{
    public function post(
        string $businessKey,
        int|string $entityRef,
        string $sourceType,
        int|string $sourceId,
        array $entries,
        ?string $correlationId = null,
        ?string $occurredAt = null,
    ): JournalBatch {
        throw new \RuntimeException('journal fixture failure');
    }

    public function postReversal(
        string $originalBusinessKey,
        string $reason,
        JournalReversalKind $kind = JournalReversalKind::Reversal,
        ?string $correlationId = null,
        ?string $occurredAt = null,
    ): JournalBatch {
        throw new \RuntimeException('journal fixture failure');
    }
}
