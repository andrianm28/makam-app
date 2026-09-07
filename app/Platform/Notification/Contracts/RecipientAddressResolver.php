<?php

declare(strict_types=1);

namespace App\Platform\Notification\Contracts;

use App\Platform\Notification\Recipient;

/**
 * Resolves a `Recipient`'s opaque `actorRef` to a real email address, or
 * `null` when none can be found. Added alongside `Channels\MailChannel` —
 * see that class's own doc block — as the one place that understands the
 * three shapes `actorRef` can carry: a plain `users.id` (every staff role
 * via `scope_assignments.actor_identifier`, and an authenticated customer's
 * `ownerRef`, including a `marketplace_order`'s `customer_ref`),
 * `ProvisionalAggregateNotificationSubjectSource::GUEST_ORDER_PARTY_PREFIX`
 * . an `order_parties.id` (an anonymous customer, resolved via their Step 6
 * contact details), or `ProvisionalAggregateNotificationSubjectSource::
 * VENDOR_ORDER_CUSTOMER_PREFIX` . a `vendor_orders.id` (a vendor order's
 * inline, always-present `customer_email`).
 *
 * A separate contract, not a method on `Contracts\Channel`, because address
 * resolution is channel-agnostic — a future WhatsApp channel needs the SAME
 * `actorRef` interpretation to resolve a phone number instead, and
 * `Channel::send()`'s signature (`RecipientSet`, not one `Recipient`)
 * already commits it to batch semantics that address resolution has no use
 * for.
 *
 * MUST NOT throw for an unresolvable reference — mirrors
 * `NotificationSubjectSource`'s own contract: a missing row is a normal
 * "cannot reach this recipient" outcome, not an error.
 */
interface RecipientAddressResolver
{
    public function emailFor(Recipient $recipient): ?string;
}
