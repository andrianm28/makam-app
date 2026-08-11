<?php

declare(strict_types=1);

namespace App\Platform\Notification\Channels;

use App\Platform\Notification\Contracts\Channel;
use App\Platform\Notification\DeliveryResult;
use App\Platform\Notification\DeliveryState;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\RecipientSet;

/**
 * Explicit `Channel` implementation for a channel that is closed by
 * configuration. It never retries and never returns a provider reference or
 * a sent state.
 *
 * NOT currently bound to `Contracts\Channel` (`Providers\
 * NotificationServiceProvider` binds only `LogChannel`) and NOT reachable
 * today: the one closed channel this module has (WA under
 * `WhatsAppMode::EmailInAppFallback`) is recorded `UNAVAILABLE` directly by
 * `Actions\DispatchNotification::consumeOutboxEvent()` before dispatch, so
 * it never reaches a `Channel::send()` call. This class is a ready-made
 * binding target for a future channel that must report `UNAVAILABLE` from
 * inside `send()` instead.
 */
final class NullChannel implements Channel
{
    public function send(
        NotificationDelivery $delivery,
        NotificationTemplateVersion $version,
        RecipientSet $recipients,
    ): DeliveryResult {
        return new DeliveryResult(
            DeliveryState::Unavailable,
            message: DeliveryResult::CHANNEL_UNAVAILABLE,
            retryable: false,
        );
    }
}
