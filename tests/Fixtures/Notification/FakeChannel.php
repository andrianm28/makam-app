<?php

declare(strict_types=1);

namespace Tests\Fixtures\Notification;

use App\Platform\Notification\Contracts\Channel;
use App\Platform\Notification\DeliveryResult;
use App\Platform\Notification\DeliveryState;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\RecipientSet;
use RuntimeException;

/**
 * Deterministic test double for `Contracts\Channel`. Task 4 also supplies
 * the development `LogChannel`/`NullChannel`; this fixture remains useful for
 * forcing success, failure, and permanent-failure outcomes.
 */
final class FakeChannel implements Channel
{
    /**
     * @var list<NotificationDelivery>
     */
    public array $sent = [];

    public function __construct(
        private readonly bool $throws = false,
        private readonly DeliveryState $resultState = DeliveryState::Sent,
        private readonly bool $retryable = true,
    ) {}

    public function send(NotificationDelivery $delivery, NotificationTemplateVersion $version, RecipientSet $recipients): DeliveryResult
    {
        $this->sent[] = $delivery;

        if ($this->throws) {
            throw new RuntimeException('FakeChannel forced failure.');
        }

        return new DeliveryResult($this->resultState, providerRef: 'fake-provider-ref', retryable: $this->retryable);
    }
}
