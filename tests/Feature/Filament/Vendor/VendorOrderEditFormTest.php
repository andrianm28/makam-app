<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Vendor;

use App\Domain\Marketplace\MarketplaceAuditActions;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Filament\Vendor\Resources\VendorOrders\Pages\EditVendorOrder;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Outbox\Models\OutboxEvent;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\MakesVendorOrderFixtures;
use Tests\TestCase;

/**
 * Proves the order edit page's form submission is wired to
 * `App\Domain\Marketplace\Actions\UpdateVendorOrderStatus` (via
 * `Pages\EditVendorOrder::handleRecordUpdate()`) — mirroring
 * `EditFaqArticleTest`'s reasoning for why the audit row, not just the
 * resulting column value, is what proves the Domain Action ran rather than
 * Filament's default `$record->update($data)`. The audit row is a side
 * effect only the Action produces; a save that just flipped the column
 * would pass a column-value assertion and fail this one.
 *
 * Also proves the status field's `in:` closed-list rule: a posted value
 * outside `VendorProcessingStatus::KNOWN_STATUSES` is a readable validation
 * error, not a 500 from the Action's `assertKnown()`.
 */
final class VendorOrderEditFormTest extends TestCase
{
    use MakesVendorOrderFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Asset builds run in CI, never on a dev host (this repo's
        // CLAUDE.md), so `public/build/manifest.json` is absent here.
        $this->withoutVite();

        // A Livewire component test renders with no current panel, and URL
        // generation (`Resource::getUrl()`) falls back to the DEFAULT panel —
        // the admin panel — whose routes do not host this vendor resource
        // (`filament.admin.resources.orders.index` does not exist). Point the
        // generator at the vendor panel for the duration of the test.
        Filament::setCurrentPanel('vendor');
    }

    protected function tearDown(): void
    {
        // `FilamentManager` is a container singleton, so the current panel
        // leaks into the next test unless reset here.
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    public function test_submitting_the_edit_form_audits_the_status_change_via_the_domain_action(): void
    {
        [$user, $order] = $this->vendorOrderForGrantedVendor();

        Livewire::test(EditVendorOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm(['status' => VendorProcessingStatus::DIPROSES])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(VendorProcessingStatus::DIPROSES, $order->refresh()->status);

        $event = AuditEvent::query()
            ->where('action', MarketplaceAuditActions::ORDER_STATUS_CHANGED)
            ->where('subject_id', (string) $order->id)
            ->sole();
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('vendor', $event->actor_role);
        $this->assertSame(
            [
                'previous_state' => VendorProcessingStatus::MENUNGGU_VENDOR,
                'new_state' => VendorProcessingStatus::DIPROSES,
            ],
            $event->metadata,
        );
    }

    public function test_a_vendor_can_cancel_an_order_through_the_form(): void
    {
        // DIBATALKAN has no dedicated one-click action
        // (`VendorOrderStatusTransitionActionsTest`'s six actions are accept/
        // reject/process/sendSchedule/complete/complain) — the form's status
        // `Select` is the only surface that reaches it, per
        // `VendorOrderForm`'s doc block ("No transition graph, deliberately").
        [$user, $order] = $this->vendorOrderForGrantedVendor();

        Livewire::test(EditVendorOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm(['status' => VendorProcessingStatus::DIBATALKAN])
            ->call('save')
            ->assertHasNoFormErrors();

        $order->refresh();
        $this->assertSame(VendorProcessingStatus::DIBATALKAN, $order->status);
        $this->assertTrue($order->isCancelled());

        $event = AuditEvent::query()
            ->where('action', MarketplaceAuditActions::ORDER_STATUS_CHANGED)
            ->where('subject_id', (string) $order->id)
            ->sole();
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame(
            [
                'previous_state' => VendorProcessingStatus::MENUNGGU_VENDOR,
                'new_state' => VendorProcessingStatus::DIBATALKAN,
            ],
            $event->metadata,
        );

        // `vendor_order.decided.v1` is scoped to the MENUNGGU_VENDOR ->
        // DITERIMA_VENDOR/DITOLAK_VENDOR decision only (see
        // `UpdateVendorOrderStatus`'s class doc block) — a cancellation is
        // audited but genuinely produces no outbox event today. This is the
        // documented behaviour, not an oversight this test papers over.
        $this->assertNull(
            OutboxEvent::query()->where('event_name', 'vendor_order.decided.v1')->first(),
        );
    }

    public function test_submitting_an_out_of_list_status_fails_validation_without_writing(): void
    {
        [, $order] = $this->vendorOrderForGrantedVendor();

        // `DIBAYAR` is deliberately NOT in `VendorProcessingStatus::KNOWN_
        // STATUSES` (AC12: payment and fulfilment are separate states). The
        // Select's `->options()` only constrains the UI; this proves the
        // server-side `in:` rule rejects a posted value the UI never offered.
        Livewire::test(EditVendorOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm(['status' => 'DIBAYAR'])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(VendorProcessingStatus::MENUNGGU_VENDOR, $order->refresh()->status);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_saving_only_notes_without_a_status_change_does_not_audit(): void
    {
        [$user, $order] = $this->vendorOrderForGrantedVendor();

        // `UpdateVendorOrderStatus` audits only when the status actually
        // moves; a notes-only save writes the note and no audit row — notes
        // is the vendor's internal scratch field, not fulfilment history.
        Livewire::test(EditVendorOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm(['notes' => 'Catatan internal.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Catatan internal.', $order->refresh()->notes);
        $this->assertSame(0, AuditEvent::query()->count());
    }
}
