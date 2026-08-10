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
 * Explicit stand-in for a channel that is closed by configuration. It never
 * retries and never returns a provider reference or a sent state.
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
