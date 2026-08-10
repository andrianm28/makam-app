<?php

declare(strict_types=1);

namespace App\Platform\Notification;

/**
 * The outcome a `Contracts\Channel::send()` implementation reports back —
 * task-3-brief.md D5. Deliberately minimal (Task 3's job is only to define
 * the shape the per-channel dispatch job records; Task 4 "fleshes out the
 * outcome mapping" — i.e. decides which real provider responses map to
 * which `DeliveryState`).
 */
final class DeliveryResult
{
    public function __construct(
        public readonly DeliveryState $state,
        public readonly ?string $providerRef = null,
        public readonly ?string $message = null,
    ) {}
}
