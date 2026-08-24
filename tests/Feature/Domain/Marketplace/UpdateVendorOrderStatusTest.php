<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\Actions\UpdateVendorOrderStatus;
use App\Domain\Marketplace\MarketplaceAuditActions;
use App\Domain\Marketplace\Models\VendorOrder;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\MakesVendorOrderFixtures;
use Tests\TestCase;

/**
 * `UpdateVendorOrderStatus` is the ONE write path for
 * `vendor_orders.status` — the edit form and the six one-click transition
 * actions both call it (`Pages\EditVendorOrder`). This file proves the
 * Action's own contract; `VendorOrderStatusTransitionActionsTest` proves the
 * UI is actually wired to it, mirroring the FAQ lane's split between the
 * Actions' internal correctness and the wiring-only proofs.
 *
 * The load-bearing assertions are:
 *  - the audit row's `previous_state`/`new_state` metadata is written in the
 *    SAME database transaction as the status change (the row existing at all
 *    after the Action returns is what the wiring tests lean on), and
 *  - an out-of-list status aborts before any write, so the closed list is
 *    enforced even by a caller that skips the form's `in:` rule.
 *
 * Deliberately no transition-graph assertions here: `VendorProcessingStatus`
 * defines a closed list and no legal-moves ordering, and the Action accepts
 * any known status by design (see its class doc block).
 */
final class UpdateVendorOrderStatusTest extends TestCase
{
    use MakesVendorOrderFixtures;
    use RefreshDatabase;

    private function makeOrder(
        string $status = VendorProcessingStatus::MENUNGGU_VENDOR,
        ?string $notes = null,
    ): VendorOrder {
        // The fixture's actor grant is irrelevant here — the Action takes its
        // actor explicitly and never reads the acting user — but reusing the
        // shared fixture keeps one definition of the vendor/product/listing/
        // order graph.
        [, $order] = $this->vendorOrderForGrantedVendor($status);
        $order->forceFill(['notes' => $notes])->save();

        return $order;
    }

    public function test_it_transitions_the_status_and_records_an_audit_row_in_the_same_transaction(): void
    {
        $order = $this->makeOrder();

        $updated = (new UpdateVendorOrderStatus)(
            order: $order,
            status: VendorProcessingStatus::DITERIMA_VENDOR,
            actorReference: 42,
            actorRole: 'vendor',
        );

        $this->assertSame(VendorProcessingStatus::DITERIMA_VENDOR, $updated->status);

        $event = AuditEvent::query()
            ->where('action', MarketplaceAuditActions::ORDER_STATUS_CHANGED)
            ->where('subject_id', (string) $order->id)
            ->sole();
        $this->assertSame((string) $order->id, $event->subject_id);
        $this->assertSame('vendor_order', $event->subject_type);
        $this->assertSame('42', $event->actor_ref);
        $this->assertSame('vendor', $event->actor_role);
        $this->assertSame(AuditSource::Panel->value, $event->source);
        $this->assertSame(
            [
                'previous_state' => VendorProcessingStatus::MENUNGGU_VENDOR,
                'new_state' => VendorProcessingStatus::DITERIMA_VENDOR,
            ],
            $event->metadata,
        );
    }

    public function test_it_rejects_a_status_outside_the_closed_list_without_writing_anything(): void
    {
        $order = $this->makeOrder();

        try {
            (new UpdateVendorOrderStatus)(
                order: $order,
                status: 'DIBAYAR',
                actorReference: 42,
                actorRole: 'vendor',
            );
            $this->fail('Expected InvalidArgumentException for an unknown status.');
        } catch (InvalidArgumentException) {
            // Expected — the closed list is enforced even by a caller that
            // bypasses the form's `in:` rule.
        }

        $this->assertSame(VendorProcessingStatus::MENUNGGU_VENDOR, $order->refresh()->status);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_it_records_no_audit_row_when_only_notes_change(): void
    {
        $order = $this->makeOrder(notes: 'Catatan lama.');

        (new UpdateVendorOrderStatus)(
            order: $order,
            status: VendorProcessingStatus::MENUNGGU_VENDOR,
            actorReference: 42,
            actorRole: 'vendor',
            notes: 'Catatan baru.',
        );

        $this->assertSame('Catatan baru.', $order->refresh()->notes);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_null_notes_clears_the_column(): void
    {
        $order = $this->makeOrder(notes: 'Catatan lama.');

        (new UpdateVendorOrderStatus)(
            order: $order,
            status: VendorProcessingStatus::DIPROSES,
            actorReference: 42,
            actorRole: 'vendor',
            notes: null,
        );

        $this->assertNull($order->refresh()->notes);
        // A real transition still audits even when notes clear.
        $this->assertSame(
            VendorProcessingStatus::DIPROSES,
            AuditEvent::query()->sole()->metadata['new_state'],
        );
    }

    public function test_a_notes_value_is_written_alongside_the_transition(): void
    {
        $order = $this->makeOrder();

        (new UpdateVendorOrderStatus)(
            order: $order,
            status: VendorProcessingStatus::DITOLAK_VENDOR,
            actorReference: 42,
            actorRole: 'vendor',
            notes: 'Alasan penolakan.',
        );

        $this->assertSame('Alasan penolakan.', $order->refresh()->notes);
        $this->assertSame(
            VendorProcessingStatus::DITOLAK_VENDOR,
            AuditEvent::query()->sole()->metadata['new_state'],
        );
    }

    public function test_waiting_to_accepted_emits_vendor_order_decided_with_the_accepted_outcome(): void
    {
        $order = $this->makeOrder(status: VendorProcessingStatus::MENUNGGU_VENDOR);

        (new UpdateVendorOrderStatus)(
            order: $order,
            status: VendorProcessingStatus::DITERIMA_VENDOR,
            actorReference: 42,
            actorRole: 'vendor',
        );

        $outbox = OutboxEvent::query()
            ->where('event_name', 'vendor_order.decided.v1')
            ->where('aggregate_id', $order->getKey())
            ->sole();

        $this->assertSame(1, $outbox->event_version);
        $this->assertSame('vendor_order', $outbox->aggregate_type);
        $this->assertSame(OutboxClassification::Internal->value, $outbox->classification);
        $this->assertSame("vendor_order_decided:{$order->getKey()}", $outbox->idempotency_key);
        $this->assertEqualsCanonicalizing([
            'vendor_order_id' => $order->getKey(),
            'outcome' => VendorProcessingStatus::DITERIMA_VENDOR,
        ], $outbox->payload);
    }

    public function test_waiting_to_rejected_emits_vendor_order_decided_with_the_rejected_outcome(): void
    {
        $order = $this->makeOrder(status: VendorProcessingStatus::MENUNGGU_VENDOR);

        (new UpdateVendorOrderStatus)(
            order: $order,
            status: VendorProcessingStatus::DITOLAK_VENDOR,
            actorReference: 42,
            actorRole: 'vendor',
        );

        $outbox = OutboxEvent::query()
            ->where('event_name', 'vendor_order.decided.v1')
            ->where('aggregate_id', $order->getKey())
            ->sole();

        $this->assertSame("vendor_order_decided:{$order->getKey()}", $outbox->idempotency_key);
        $this->assertEqualsCanonicalizing([
            'vendor_order_id' => $order->getKey(),
            'outcome' => VendorProcessingStatus::DITOLAK_VENDOR,
        ], $outbox->payload);
    }

    public function test_waiting_to_in_progress_does_not_emit_vendor_order_decided(): void
    {
        $order = $this->makeOrder(status: VendorProcessingStatus::MENUNGGU_VENDOR);

        (new UpdateVendorOrderStatus)(
            order: $order,
            status: VendorProcessingStatus::DIPROSES,
            actorReference: 42,
            actorRole: 'vendor',
        );

        $this->assertNull(
            OutboxEvent::query()->where('event_name', 'vendor_order.decided.v1')->first()
        );
    }

    public function test_in_progress_to_accepted_does_not_emit_vendor_order_decided(): void
    {
        // Discrimination is on `$previousStatus`, not only `$status`: this
        // Action accepts any known target from any source (see the class
        // doc block, "No transition graph, deliberately"), so an unrealistic
        // DIPROSES -> DITERIMA_VENDOR call must still be gated on the real
        // "waiting for vendor decision" source, exactly as
        // `Listeners\DispatchOrderNotifications`'s `DITOLAK` arm gates on
        // `from_status`, not `to_status` alone.
        $order = $this->makeOrder(status: VendorProcessingStatus::DIPROSES);

        (new UpdateVendorOrderStatus)(
            order: $order,
            status: VendorProcessingStatus::DITERIMA_VENDOR,
            actorReference: 42,
            actorRole: 'vendor',
        );

        $this->assertNull(
            OutboxEvent::query()->where('event_name', 'vendor_order.decided.v1')->first()
        );
    }
}
