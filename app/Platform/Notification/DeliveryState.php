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
 * Task 3 only ever writes `Queued` and `Unavailable` (see
 * `Actions\DispatchNotification`); `Sent`/`Delivered`/`Failed` are written
 * by the per-channel outcome-recording path this task also builds
 * (`sendViaChannel()`), driven by whatever `DeliveryResult` a
 * `Contracts\Channel` implementation returns — Task 4 owns the real
 * outcome-mapping policy for those, including whether/when `Delivered` is
 * ever reachable given `Contracts\Channel::send()`'s synchronous return
 * shape.
 *
 * `Unavailable` is not a failure — it is the honest record of "this
 * channel was never attempted" (e.g. WhatsApp dropped by
 * `WhatsAppMode::EmailInAppFallback`, task-3-brief.md D4/AC2/AC12): a real
 * row in this state, not a silently missing one, is what AC4 requires.
 */
enum DeliveryState: string
{
    case Queued = 'QUEUED';
    case Sent = 'SENT';
    case Delivered = 'DELIVERED';
    case Failed = 'FAILED';
    case Unavailable = 'UNAVAILABLE';
}
