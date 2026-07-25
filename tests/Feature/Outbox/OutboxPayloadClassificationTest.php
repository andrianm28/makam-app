<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Platform\Outbox\Exceptions\OutboxPayloadKeyNotAllowedException;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use App\Platform\Outbox\PayloadClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AC7: "SHALL NOT include restricted data in an event — references only."
 * See `PayloadClassification`'s own doc block for why this is a narrow
 * key-name denylist rather than an allowlist (unlike
 * `App\Platform\Audit\MetadataAllowlist`), and for what it deliberately
 * does not catch (value-level content under a differently-named key).
 */
final class OutboxPayloadClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_top_level_denylisted_key_is_rejected(): void
    {
        $this->expectException(OutboxPayloadKeyNotAllowedException::class);

        Outbox::record(
            eventName: 'fixture.payload_test.v1',
            eventVersion: 1,
            aggregateType: 'fixture',
            aggregateId: 1,
            data: ['ktp_number' => '3271...'],
            classification: OutboxClassification::Internal,
        );
    }

    public function test_a_nested_denylisted_key_is_rejected(): void
    {
        $this->expectException(OutboxPayloadKeyNotAllowedException::class);

        Outbox::record(
            eventName: 'fixture.payload_test.v1',
            eventVersion: 1,
            aggregateType: 'fixture',
            aggregateId: 1,
            data: ['customer' => ['name' => 'Budi', 'bank_account' => '1234567890']],
            classification: OutboxClassification::Internal,
        );
    }

    public function test_a_denylisted_key_check_is_case_insensitive(): void
    {
        $this->expectException(OutboxPayloadKeyNotAllowedException::class);

        PayloadClassification::assertSafe(['NIK' => '3271...']);
    }

    public function test_a_reference_only_payload_is_accepted(): void
    {
        $row = Outbox::record(
            eventName: 'fixture.payload_test.v1',
            eventVersion: 1,
            aggregateType: 'fixture',
            aggregateId: 1,
            data: ['document_id' => 'doc-123', 'amount' => 150000, 'currency' => 'IDR'],
            classification: OutboxClassification::Internal,
        );

        $this->assertInstanceOf(OutboxEvent::class, $row);
        $this->assertSame(['document_id' => 'doc-123', 'amount' => 150000, 'currency' => 'IDR'], $row->payload);
    }

    public function test_a_restricted_classification_is_a_legitimate_declared_value_on_its_own(): void
    {
        // AC7 forbids restricted DATA in the payload, not the RESTRICTED
        // classification label itself — event-catalog.md's
        // document.accessed.v1 is explicitly a "Sensitive event." A
        // reference-only payload declared RESTRICTED must still be
        // accepted; PayloadClassification's denylist is what actually
        // guards content, independent of the declared label.
        $row = Outbox::record(
            eventName: 'document.accessed.v1',
            eventVersion: 1,
            aggregateType: 'fixture',
            aggregateId: 1,
            data: ['document_id' => 'doc-999'],
            classification: OutboxClassification::Restricted,
        );

        $this->assertSame(OutboxClassification::Restricted->value, $row->classification);
    }

    public function test_an_invalid_classification_string_cannot_even_be_constructed(): void
    {
        // Structural validation via the enum type itself — no custom
        // exception needed for this half of AC7's "declared classification"
        // handling. \ValueError is PHP's own backed-enum failure mode.
        $this->expectException(\ValueError::class);

        OutboxClassification::from('NOT_A_REAL_CLASSIFICATION');
    }
}
