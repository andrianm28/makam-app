<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\Notification\Contracts\NotificationVariableSource;

/**
 * Pass-through of the outbox payload's scalar `data` keys. This is what the
 * `Vendor accepted/rejected` (`vendor_order_id`, `outcome`) and `Marketplace
 * order submitted` (`order_id`) templates reference — their producers put
 * exactly those non-restricted references in `Outbox::record(data: ...)`.
 * Nested arrays are dropped, never flattened.
 */
final class PayloadNotificationVariableSource implements NotificationVariableSource
{
    public function handles(string $aggregateType): bool
    {
        return true;
    }

    public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $bag = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && ($value === null || is_scalar($value))) {
                $bag[$key] = $value;
            }
        }

        return $bag;
    }
}
