<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Payment\Exceptions\ProviderEventIsAppendOnlyException;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\ProviderEventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `provider_events` is design.md §Data's "raw durable webhook store, unique by
 * provider event id" and "append-only … the replay source of truth". This test
 * pins the four properties the rest of the webhook pipeline relies on:
 * durability of the raw body, encryption of that body at rest, the two unique
 * guards, and append-only-ness.
 */
final class ProviderEventModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function event(array $overrides = []): ProviderEvent
    {
        return ProviderEvent::create(array_merge([
            'provider' => PaymentProviders::SUMOPOD_SANDBOX,
            'provider_event_id' => 'msg_'.bin2hex(random_bytes(6)),
            'event_id_source' => 'svix-id',
            'provider_transaction_id' => 'pay_1',
            'invoice_reference' => 'order_1',
            'event_type' => 'payment.completed',
            'merchant_ref' => 'makam-sandbox',
            'amount_minor' => 1_500_000_00,
            'declared_currency' => 'IDR',
            'raw_payload' => '{"event_type":"payment.completed"}',
            'payload_digest' => hash('sha256', '{"event_type":"payment.completed"}'),
            'status' => ProviderEventStatus::Received->value,
            'received_at' => CarbonImmutable::now(),
        ], $overrides));
    }

    public function test_table_exists_with_the_columns_the_pipeline_reads(): void
    {
        $this->assertTrue(Schema::hasTable('provider_events'));

        foreach ([
            'id', 'provider', 'provider_event_id', 'event_id_source',
            'provider_transaction_id', 'invoice_reference', 'event_type',
            'merchant_ref', 'amount_minor', 'declared_currency',
            'event_occurred_at', 'raw_payload', 'payload_digest',
            'signature_mechanism', 'status', 'rejection_detail',
            'received_at', 'validated_at', 'correlation_id',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('provider_events', $column),
                "provider_events is missing column [{$column}]"
            );
        }
    }

    public function test_raw_payload_is_encrypted_at_rest_and_transparently_readable(): void
    {
        $body = '{"event_type":"payment.completed","data":{"order_id":"INV-77"}}';

        $event = $this->event(['raw_payload' => $body]);

        $stored = DB::table('provider_events')->where('id', $event->getKey())->value('raw_payload');

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('INV-77', $stored, 'raw_payload is stored in plaintext');
        $this->assertNotSame($body, $stored);
        $this->assertSame($body, $event->fresh()->raw_payload);
    }

    public function test_provider_and_event_id_are_unique(): void
    {
        $this->event(['provider_event_id' => 'msg_dup']);

        $this->expectException(QueryException::class);

        $this->event(['provider_event_id' => 'msg_dup', 'provider_transaction_id' => 'pay_other']);
    }

    public function test_a_second_settling_event_for_the_same_transaction_and_invoice_collides(): void
    {
        $this->event(['provider_transaction_id' => 'pay_9', 'invoice_reference' => 'order_9']);

        $this->expectException(QueryException::class);

        // Different provider event id, same settled (transaction, invoice)
        // pair — payment-webhook.md's secondary guard.
        $this->event(['provider_transaction_id' => 'pay_9', 'invoice_reference' => 'order_9']);
    }

    public function test_a_non_settling_event_for_an_already_settled_transaction_still_persists(): void
    {
        $this->event([
            'provider_transaction_id' => 'pay_10',
            'invoice_reference' => 'order_10',
            'event_type' => 'payment.completed',
        ]);

        // Out-of-order delivery (Task 4) requires the later `expired` event to
        // be receivable and durable, so the secondary guard covers settling
        // events only.
        $late = $this->event([
            'provider_transaction_id' => 'pay_10',
            'invoice_reference' => 'order_10',
            'event_type' => 'payment.expired',
        ]);

        $this->assertTrue($late->exists);
        $this->assertSame(2, ProviderEvent::query()->where('provider_transaction_id', 'pay_10')->count());
    }

    public function test_the_row_is_append_only_except_for_its_lifecycle_columns(): void
    {
        $event = $this->event();

        $event->markStatus(ProviderEventStatus::Validated);

        $this->assertSame(ProviderEventStatus::Validated->value, $event->fresh()->status);

        $this->expectException(ProviderEventIsAppendOnlyException::class);

        $event->update(['raw_payload' => 'tampered']);
    }

    public function test_the_row_cannot_be_deleted(): void
    {
        $event = $this->event();

        $this->expectException(ProviderEventIsAppendOnlyException::class);

        $event->delete();
    }

    public function test_status_must_be_a_member_of_the_closed_list(): void
    {
        $this->expectException(ProviderEventIsAppendOnlyException::class);

        $this->event(['status' => 'NOT_A_STATUS']);
    }
}
