<?php

declare(strict_types=1);

namespace Tests\Fixtures\Notification;

use App\Platform\Notification\Contracts\Channel;
use App\Platform\Notification\DeliveryResult;
use App\Platform\Notification\DeliveryState;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use RuntimeException;

/**
 * Test double for `Contracts\Channel` — task-3-brief.md D5: "Task 3 creates
 * `Contracts/Channel.php` (the interface only) ... Your own tests bind a
 * test-double `Channel`." No `LogChannel`/`NullChannel` implementation
 * exists yet (Task 4's scope), so this is the only `Channel` this lane's
 * own tests can bind.
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
    ) {}

    public function send(NotificationDelivery $delivery, NotificationTemplateVersion $version): DeliveryResult
    {
        $this->sent[] = $delivery;

        if ($this->throws) {
            throw new RuntimeException('FakeChannel forced failure.');
        }

        return new DeliveryResult($this->resultState, providerRef: 'fake-provider-ref');
    }
}
