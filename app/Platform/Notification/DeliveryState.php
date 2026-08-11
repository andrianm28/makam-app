<?php

declare(strict_types=1);

namespace App\Platform\Notification;

/**
 * The closed set of states a `notification_deliveries` row may hold —
 * task-3-brief.md D5: created by Task 3 because Task 3 cannot record a
 * `QUEUED` state without it, even though the plan's File Structure had
 * originally placed this file under Task 4.
 *
 * AC4 (`AGENTS.md` §Notifications: "Do not claim WhatsApp/email delivery
 * without delivery state"): a delivery row is created the moment it is
 * queued, in `QUEUED` state, so "no delivery row" and "delivery attempted"
 * are always distinguishable — there is no window where a queued send is
 * invisible.
 *
 * `Sent`/`Delivered` are two distinct states, not one — `Sent` means "the
 * channel accepted it," `Delivered` means "the provider confirmed receipt."
 * `Actions\DispatchNotification` records initial `Queued`/`Unavailable`
 * rows; `SendNotificationChannelJob` records channel outcomes, and
 * `RetryFailedDeliveryJob` moves transient failures back to `Queued` within
 * its bounded retry policy.
 *
 * `Unavailable` is not a failure — it is the honest record of "this
 * channel was never attempted" (e.g. WhatsApp dropped by
 * `WhatsAppMode::EmailInAppFallback`, task-3-brief.md D4/AC2/AC12): a real
 * row in this state, not a silently missing one, is what AC4 requires.
 *
 * `Unavailable` has more than one cause — the WA-gate closure is only one
 * of them; a missing active template version (`DeliveryResult::
 * TEMPLATE_VERSION_UNAVAILABLE`, `Actions\DispatchNotification` and
 * `Jobs\SendNotificationChannelJob`) and a channel-reported unavailable
 * outcome (`DeliveryResult::CHANNEL_UNAVAILABLE`) both also record
 * `Unavailable`, on ANY channel including EMAIL. `presentation()` therefore
 * takes the row's `failure_message` to tell these apart: the WA-gate case
 * is the only one that leaves `failure_message` null (Actions\
 * DispatchNotification never sets one when the cause is the mode gate), so
 * only that case may render the WhatsApp-specific copy. Every other cause
 * renders a channel-neutral label — never the raw `failure_message`, which
 * may carry a provider-controlled error code and must not reach the UI
 * verbatim.
 */
enum DeliveryState: string
{
    case Queued = 'QUEUED';
    case Sent = 'SENT';
    case Delivered = 'DELIVERED';
    case Failed = 'FAILED';
    case Unavailable = 'UNAVAILABLE';

    /**
     * The only presentation mapping a delivery-state UI may use. A failed
     * state remains visually distinct from an unavailable channel and from a
     * queued pending delivery.
     *
     * `$failureMessage` is the delivery row's own `failure_message` column —
     * required to disambiguate `Unavailable`'s causes (see the enum doc
     * block). Ignored for every other state.
     *
     * @return array{intent: string, label: string}
     */
    public function presentation(?string $failureMessage = null): array
    {
        return match ($this) {
            self::Queued => ['intent' => 'pending', 'label' => 'Sedang dikirim'],
            self::Sent, self::Delivered => ['intent' => 'success', 'label' => 'Terkirim'],
            self::Unavailable => ['intent' => 'neutral', 'label' => $failureMessage === null
                ? 'WhatsApp belum tersedia'
                : 'Notifikasi tidak tersedia'],
            self::Failed => ['intent' => 'danger', 'label' => 'Gagal mengirim'],
        };
    }
}
