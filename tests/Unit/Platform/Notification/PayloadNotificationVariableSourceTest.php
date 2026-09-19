<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\PayloadNotificationVariableSource;
use PHPUnit\Framework\TestCase;

final class PayloadNotificationVariableSourceTest extends TestCase
{
    public function test_it_handles_every_aggregate_type(): void
    {
        $source = new PayloadNotificationVariableSource;

        self::assertTrue($source->handles('order'));
        self::assertTrue($source->handles('vendor_order'));
    }

    public function test_it_passes_through_scalar_data_keys_only(): void
    {
        $source = new PayloadNotificationVariableSource;

        $bag = $source->variablesFor('Marketplace order submitted', 'marketplace_order', 'abc', [
            'data' => [
                'order_id' => 'MO-1',
                'outcome' => 'diterima',
                'nested' => ['not' => 'scalar'],
                'nothing' => null,
            ],
        ]);

        self::assertSame(['order_id' => 'MO-1', 'outcome' => 'diterima', 'nothing' => null], $bag);
    }

    public function test_a_payload_without_a_data_envelope_is_read_at_the_top_level(): void
    {
        $source = new PayloadNotificationVariableSource;

        self::assertSame(['order_id' => 'MO-2'], $source->variablesFor('x', 'y', 'z', ['order_id' => 'MO-2']));
    }
}
