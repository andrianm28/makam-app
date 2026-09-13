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
 * Provider-neutral development channel. Writing the rendered message to the
 * development log is NOT an external send — no email/WhatsApp provider
 * ever accepted anything — so this must never report a state that renders
 * as "Terkirim" (delivered).
 *
 * ---------------------------------------------------------------------------
 * NOTIF-07, 07 Sep 2026 — this channel used to return `DeliveryState::Sent`
 * ---------------------------------------------------------------------------
 * `DeliveryState::Sent`/`Delivered` are the ONLY states
 * `DeliveryState::presentation()` renders as "Terkirim" (AC4: "Do not claim
 * WhatsApp/email delivery without delivery state"). Reporting `Sent` here
 * made every delivery through this channel — which is every delivery on a
 * host with no real EMAIL/WA provider configured — render as genuinely
 * delivered when nothing had left the system. Option (a) from the finding:
 * this channel now returns `DeliveryState::Unavailable` with a non-null
 * `failure_message` (`LOG_ONLY_MESSAGE`), which `presentation()` already
 * renders as the neutral "Notifikasi tidak tersedia" — verified directly
 * against that enum rather than assumed, since `Unavailable`'s WA-gate
 * branch specifically requires a NULL `failure_message` to render the
 * WhatsApp-specific copy instead (see that enum's own doc block); a non-null
 * message here correctly takes the OTHER branch. No changes were needed to
 * `DeliveryState`, `delivery-state-chip.blade.php`, or any other channel —
 * the neutral rendering path this fix uses already existed for the
 * "missing template version" case.
 *
 * `retryable` is left at its default (unused): `SendNotificationChannelJob`
 * only dispatches a retry when the returned state is `DeliveryState::
 * Failed`, and this channel never returns that state, so the flag is moot
 * here.
 */
final class LogChannel implements Channel
{
    /**
     * Deliberately NOT a `DeliveryResult::*` constant alongside
     * `CHANNEL_SEND_FAILED`/`CHANNEL_UNAVAILABLE`/`TEMPLATE_VERSION_
     * UNAVAILABLE` — those three name FAILURE causes; this one names a
     * channel that, by design, never attempts an external send at all. Kept
     * here, next to the only place that produces it, rather than widening
     * `DeliveryResult`'s vocabulary for a single caller.
     */
    public const string LOG_ONLY_MESSAGE = 'NOTIFICATION_CHANNEL_LOG_ONLY';

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

        return new DeliveryResult(DeliveryState::Unavailable, providerRef: $providerRef, message: self::LOG_ONLY_MESSAGE);
    }
}
