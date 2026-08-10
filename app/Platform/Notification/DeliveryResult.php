<?php

declare(strict_types=1);

namespace App\Platform\Notification;

/**
 * The outcome a `Contracts\Channel::send()` implementation reports back.
 * `retryable` separates transient provider failures from permanent
 * validation/authorization failures without persisting provider-controlled
 * text.
 */
final class DeliveryResult
{
    public const string CHANNEL_SEND_FAILED = 'NOTIFICATION_CHANNEL_SEND_FAILED';

    public const string CHANNEL_UNAVAILABLE = 'NOTIFICATION_CHANNEL_UNAVAILABLE';

    public const string TEMPLATE_VERSION_UNAVAILABLE = 'NOTIFICATION_TEMPLATE_UNAVAILABLE';

    public function __construct(
        public readonly DeliveryState $state,
        public readonly ?string $providerRef = null,
        public readonly ?string $message = null,
        public readonly bool $retryable = true,
    ) {}
}
