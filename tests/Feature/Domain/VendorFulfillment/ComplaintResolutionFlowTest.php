<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\VendorFulfillment;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\VendorFulfillment\Actions\CreateWorkOrderFromCycle;
use App\Domain\VendorFulfillment\Actions\DismissComplaint;
use App\Domain\VendorFulfillment\Actions\FileComplaint;
use App\Domain\VendorFulfillment\Actions\ResolveComplaint;
use App\Domain\VendorFulfillment\Actions\StartInvestigatingComplaint;
use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Exceptions\InvalidComplaintTransitionException;
use App\Domain\VendorFulfillment\MakeGoodStatus;
use App\Domain\VendorFulfillment\Models\MakeGoodOrder;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Models\User;
use App\Platform\Audit\AuditSource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ComplaintResolutionFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkOrder(): WorkOrder
    {
        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Basic Care',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'product_code' => 'GC-MONTHLY',
            'status' => 'active',
            'checklist_template' => [],
        ]);
        $subscription = Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => User::factory()->create()->id,
            'status' => 'active',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'currency' => 'IDR',
        ]);
        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth(),
            'cycle_end' => now(),
            'status' => 'PAID',
        ]);

        return app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);
    }

    private function fileComplaint(): ServiceComplaint
    {
        return app(FileComplaint::class)(
            $this->makeWorkOrder(),
            User::factory()->create()->id,
            'Service was not performed properly.',
        );
    }

    public function test_start_investigating_transitions_open_to_investigating(): void
    {
        $complaint = $this->fileComplaint();

        $result = app(StartInvestigatingComplaint::class)($complaint);

        $this->assertSame(ComplaintStatus::Investigating->value, $result->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'COMPLAINT_INVESTIGATING',
            'subject_type' => 'service_complaint',
            'subject_id' => (string) $complaint->getKey(),
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'care.complaint_investigating.v1',
            'aggregate_type' => 'service_complaint',
            'aggregate_id' => (string) $complaint->getKey(),
        ]);
    }

    public function test_start_investigating_refuses_a_complaint_not_open(): void
    {
        $complaint = $this->fileComplaint();
        app(StartInvestigatingComplaint::class)($complaint);

        $this->expectException(InvalidComplaintTransitionException::class);

        app(StartInvestigatingComplaint::class)($complaint->fresh());
    }

    public function test_resolve_transitions_open_to_resolved_without_make_good(): void
    {
        $complaint = $this->fileComplaint();

        $result = app(ResolveComplaint::class)($complaint, 'Talked to the vendor, agreed to redo next visit.', false);

        $this->assertSame(ComplaintStatus::Resolved->value, $result->status);
        $this->assertSame('Talked to the vendor, agreed to redo next visit.', $result->resolution_notes);
        $this->assertNotNull($result->resolved_at);
        $this->assertNull($result->make_good_order_id);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'COMPLAINT_RESOLVED',
            'subject_type' => 'service_complaint',
            'subject_id' => (string) $complaint->getKey(),
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'care.complaint_resolved.v1',
            'aggregate_type' => 'service_complaint',
            'aggregate_id' => (string) $complaint->getKey(),
        ]);
    }

    public function test_resolve_transitions_investigating_to_resolved_without_make_good(): void
    {
        $complaint = $this->fileComplaint();
        app(StartInvestigatingComplaint::class)($complaint);

        $result = app(ResolveComplaint::class)($complaint->fresh(), 'Talked to the vendor, agreed to redo next visit.', false);

        $this->assertSame(ComplaintStatus::Resolved->value, $result->status);
        $this->assertSame('Talked to the vendor, agreed to redo next visit.', $result->resolution_notes);
    }

    public function test_resolve_with_make_good_creates_and_links_a_make_good_order(): void
    {
        $complaint = $this->fileComplaint();

        $result = app(ResolveComplaint::class)(
            $complaint,
            'Headstone was not cleaned properly, issuing a redo.',
            true,
            'Redo the cleaning pass.',
            'admin',
            AuditSource::Panel,
            'admin-user-ref',
        );

        $this->assertSame(ComplaintStatus::Resolved->value, $result->status);
        $this->assertNotNull($result->make_good_order_id);

        $makeGood = MakeGoodOrder::query()->findOrFail($result->make_good_order_id);
        $this->assertSame(MakeGoodStatus::Pending->value, $makeGood->status);
        $this->assertSame('Redo the cleaning pass.', $makeGood->notes);

        // Real actor attribution flows through to CreateMakeGood's own audit row.
        $this->assertDatabaseHas('audit_events', [
            'action' => 'MAKE_GOOD_CREATED',
            'actor_role' => 'admin',
            'actor_ref' => 'admin-user-ref',
        ]);
    }

    public function test_resolve_refuses_a_dismissed_complaint(): void
    {
        $complaint = $this->fileComplaint();
        app(DismissComplaint::class)($complaint, 'Not a valid complaint.');

        $this->expectException(InvalidComplaintTransitionException::class);

        app(ResolveComplaint::class)($complaint->fresh(), 'Too late', false);
    }

    public function test_dismiss_transitions_open_to_dismissed_with_reason(): void
    {
        $complaint = $this->fileComplaint();

        $result = app(DismissComplaint::class)($complaint, 'Vendor evidence shows service was completed correctly.');

        $this->assertSame(ComplaintStatus::Dismissed->value, $result->status);
        $this->assertSame('Vendor evidence shows service was completed correctly.', $result->resolution_notes);
        $this->assertNotNull($result->resolved_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'COMPLAINT_DISMISSED',
            'subject_type' => 'service_complaint',
            'subject_id' => (string) $complaint->getKey(),
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'care.complaint_dismissed.v1',
            'aggregate_type' => 'service_complaint',
            'aggregate_id' => (string) $complaint->getKey(),
        ]);
    }

    public function test_dismiss_transitions_investigating_to_dismissed_with_reason(): void
    {
        $complaint = $this->fileComplaint();
        app(StartInvestigatingComplaint::class)($complaint);

        $result = app(DismissComplaint::class)($complaint->fresh(), 'Vendor evidence shows service was completed correctly.');

        $this->assertSame(ComplaintStatus::Dismissed->value, $result->status);
        $this->assertSame('Vendor evidence shows service was completed correctly.', $result->resolution_notes);
    }

    public function test_dismiss_refuses_an_already_resolved_complaint(): void
    {
        $complaint = $this->fileComplaint();
        app(ResolveComplaint::class)($complaint, 'Handled directly.', false);

        $this->expectException(InvalidComplaintTransitionException::class);

        app(DismissComplaint::class)($complaint->fresh(), 'Too late');
    }

    public function test_resolve_with_make_good_rolls_back_entirely_when_the_work_order_lookup_fails(): void
    {
        $complaint = $this->fileComplaint();
        $complaint->forceFill(['work_order_id' => (string) Str::uuid()])->save();

        try {
            app(ResolveComplaint::class)($complaint->fresh(), 'Issuing a redo.', true, 'Redo the cleaning pass.');
            $this->fail('Expected the missing work order to abort the resolve.');
        } catch (ModelNotFoundException) {
            // Expected: WorkOrder::firstOrFail() inside the mutation closure throws.
        }

        $this->assertSame(0, MakeGoodOrder::query()->count());
        $this->assertSame(ComplaintStatus::Open->value, $complaint->fresh()->status);
        $this->assertNull($complaint->fresh()->resolved_at);
        $this->assertNull($complaint->fresh()->make_good_order_id);
    }
}
