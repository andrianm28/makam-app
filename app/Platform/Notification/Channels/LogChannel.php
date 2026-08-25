<?php

declare(strict_types=1);

namespace App\Platform\Notification\Channels;

use App\Platform\Notification\Contracts\Channel;
use App\Platform\Notification\DeliveryResult;
use App\Platform\Notification\DeliveryState;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\RecipientSet;
use App\Platform\Notification\TemplateRenderer;
use Illuminate\Support\Facades\Log;

/**
 * Provider-neutral development channel. `SENT` means the rendered message
 * was written to the development log; it is not a claim that an email or
 * WhatsApp provider accepted an external message.
 */
final class LogChannel implements Channel
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    public function send(
        NotificationDelivery $delivery,
        NotificationTemplateVersion $version,
        RecipientSet $recipients,
    ): DeliveryResult {
        $rendered = $this->renderer->render($version, []);
        $providerRef = 'log-'.substr(hash('sha256', (string) ($delivery->provider_idempotency_key ?? $delivery->getKey())), 0, 16);

        Log::info('Notification written to development log.', [
            'channel' => $delivery->channel,
            'provider_ref' => $providerRef,
            'body' => $rendered['body'],
        ]);

        return new DeliveryResult(DeliveryState::Sent, providerRef: $providerRef);
    }
}
