<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CareSubscription;

use App\Domain\CareSubscription\Actions\MarkCyclePaid;
use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\CareSubscriptionAuditActions;
use App\Domain\CareSubscription\Exceptions\CyclePaymentAmountMismatchException;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\CareSubscription\Models\SubscriptionInvoice;
use App\Domain\CareSubscription\SubscriptionCycleStatus;
use App\Domain\CareSubscription\SubscriptionStatus;
use App\Platform\Audit\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MarkCyclePaidTest extends TestCase
{
    use RefreshDatabase;

    private function makeCarePlan(): CarePlan
    {
        return CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Test Plan',
            'product_code' => 'GRAVE_CARE_MONTHLY',
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'checklist_template' => [],
            'status' => 'active',
        ]);
    }

    private function makeDraftSubscription(): Subscription
    {
        return Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $this->makeCarePlan()->getKey(),
            'customer_id' => (string) Str::uuid(),
            'status' => SubscriptionStatus::Draft->value,
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'current_cycle_number' => 0,
            'started_at' => null,
        ]);
    }

    private function makeCycleForSubscription(Subscription $subscription): SubscriptionCycle
    {
        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => CarbonImmutable::now()->startOfMonth()->toDateString(),
            'cycle_end' => CarbonImmutable::now()->startOfMonth()->addMonth()->subDay()->toDateString(),
            'status' => SubscriptionCycleStatus::Scheduled->value,
        ]);

        $invoice = SubscriptionInvoice::query()->create([
            'subscription_cycle_id' => $cycle->getKey(),
            'amount_minor' => $subscription->price_minor,
            'currency' => $subscription->currency,
            'status' => 'pending',
            'issued_at' => now(),
        ]);

        $cycle->update(['invoice_id' => $invoice->getKey()]);

        return $cycle;
    }

    public function test_mark_paid_transitions_cycle_to_paid_and_activates_subscription(): void
    {
        $subscription = $this->makeDraftSubscription();
        $cycle = $this->makeCycleForSubscription($subscription);

        $result = app(MarkCyclePaid::class)(
            $cycle,
            150000,
            'pay-ref-001',
            'webhook:system',
        );

        $this->assertSame(SubscriptionCycleStatus::Paid->value, $result->fresh()->status);
        $this->assertSame(SubscriptionStatus::Active->value, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->started_at);
        $this->assertSame(1, $subscription->fresh()->current_cycle_number);
    }

    public function test_mark_paid_sets_invoice_to_paid(): void
    {
        $subscription = $this->makeDraftSubscription();
        $cycle = $this->makeCycleForSubscription($subscription);

        app(MarkCyclePaid::class)($cycle, 150000, 'pay-ref-002', 'webhook:system');

        $invoice = SubscriptionInvoice::query()
            ->where('subscription_cycle_id', $cycle->getKey())
            ->first();

        $this->assertNotNull($invoice);
        $this->assertSame('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_mark_paid_emits_outbox_event(): void
    {
        $subscription = $this->makeDraftSubscription();
        $cycle = $this->makeCycleForSubscription($subscription);

        app(MarkCyclePaid::class)($cycle, 150000, 'pay-ref-003', 'webhook:system');

        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'care.cycle_created.v1',
            'event_version' => 1,
            'aggregate_type' => 'subscription_cycle',
            'aggregate_id' => (string) $cycle->getKey(),
            'classification' => 'INTERNAL',
            'idempotency_key' => "cycle:{$cycle->getKey()}",
        ]);
    }

    public function test_mark_paid_records_audit(): void
    {
        $subscription = $this->makeDraftSubscription();
        $cycle = $this->makeCycleForSubscription($subscription);

        app(MarkCyclePaid::class)($cycle, 150000, 'pay-ref-004', 'webhook:system');

        $this->assertDatabaseHas('audit_events', [
            'action' => CareSubscriptionAuditActions::CYCLE_PAID,
            'subject_type' => 'subscription_cycle',
            'subject_id' => (string) $cycle->getKey(),
            'actor_ref' => 'webhook:system',
            'actor_role' => 'system',
            'outcome' => 'allowed',
        ]);
    }

    public function test_mark_paid_increments_cycle_number_for_already_active_subscription(): void
    {
        $subscription = Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $this->makeCarePlan()->getKey(),
            'customer_id' => (string) Str::uuid(),
            'status' => SubscriptionStatus::Active->value,
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'current_cycle_number' => 2,
            'started_at' => CarbonImmutable::now()->subMonthsNoOverflow(2),
        ]);

        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => CarbonImmutable::now()->subMonth()->startOfMonth()->toDateString(),
            'cycle_end' => CarbonImmutable::now()->subMonth()->startOfMonth()->addMonth()->subDay()->toDateString(),
            'status' => SubscriptionCycleStatus::Scheduled->value,
        ]);

        $invoice = SubscriptionInvoice::query()->create([
            'subscription_cycle_id' => $cycle->getKey(),
            'amount_minor' => $subscription->price_minor,
            'currency' => $subscription->currency,
            'status' => 'pending',
            'issued_at' => now(),
        ]);
        $cycle->update(['invoice_id' => $invoice->getKey()]);

        app(MarkCyclePaid::class)($cycle, 150000, 'pay-ref-005', 'webhook:system');

        $this->assertSame(3, $subscription->fresh()->current_cycle_number);
        $this->assertSame(SubscriptionStatus::Active->value, $subscription->fresh()->status);
    }

    public function test_mark_paid_throws_on_amount_mismatch_and_applies_nothing(): void
    {
        $subscription = $this->makeDraftSubscription();
        $cycle = $this->makeCycleForSubscription($subscription);

        try {
            app(MarkCyclePaid::class)($cycle, 149999, 'pay-ref-mismatch', 'webhook:system');
            $this->fail('Expected CyclePaymentAmountMismatchException');
        } catch (CyclePaymentAmountMismatchException) {
            // expected
        }

        $this->assertSame(SubscriptionCycleStatus::Scheduled->value, $cycle->fresh()->status);
        $this->assertSame(SubscriptionStatus::Draft->value, $subscription->fresh()->status);
        $this->assertSame(0, $subscription->fresh()->current_cycle_number);
        $this->assertDatabaseMissing('audit_events', [
            'action' => CareSubscriptionAuditActions::CYCLE_PAID,
            'subject_id' => (string) $cycle->getKey(),
        ]);
        $this->assertDatabaseMissing('outbox_events', [
            'aggregate_id' => (string) $cycle->getKey(),
        ]);
    }

    public function test_mark_paid_rejects_a_mismatched_amount_even_when_already_paid(): void
    {
        $subscription = $this->makeDraftSubscription();
        $cycle = $this->makeCycleForSubscription($subscription);

        app(MarkCyclePaid::class)($cycle, 150000, 'pay-ref-006', 'webhook:system');

        try {
            app(MarkCyclePaid::class)($cycle->fresh(), 1, 'pay-ref-007', 'webhook:system');
            $this->fail('Expected CyclePaymentAmountMismatchException');
        } catch (CyclePaymentAmountMismatchException) {
            // expected: the amount assert runs before the idempotency check.
        }

        // The cycle stays exactly as the first, correctly-amounted call left
        // it — the mismatched second call changed nothing.
        $this->assertSame(SubscriptionCycleStatus::Paid->value, $cycle->fresh()->status);
        $this->assertSame(1, $subscription->fresh()->current_cycle_number);
    }

    public function test_mark_paid_is_idempotent_for_an_already_paid_cycle_and_writes_no_second_audit(): void
    {
        $subscription = $this->makeDraftSubscription();
        $cycle = $this->makeCycleForSubscription($subscription);

        app(MarkCyclePaid::class)($cycle, 150000, 'pay-ref-008', 'webhook:system');
        $this->assertSame(1, $subscription->fresh()->current_cycle_number);

        $result = app(MarkCyclePaid::class)($cycle->fresh(), 150000, 'pay-ref-008-replay', 'webhook:system');

        $this->assertSame(SubscriptionCycleStatus::Paid->value, $result->status);
        // A replay must not re-advance the cycle counter.
        $this->assertSame(1, $subscription->fresh()->current_cycle_number);
        $this->assertSame(1, AuditEvent::query()
            ->where('action', CareSubscriptionAuditActions::CYCLE_PAID)
            ->where('subject_id', (string) $cycle->getKey())
            ->count());
    }
}
