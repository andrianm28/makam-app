<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\Notification\Contracts\RecipientAddressResolver;
use Illuminate\Support\Facades\DB;

/**
 * The one implementation of `Contracts\RecipientAddressResolver` — see that
 * contract's doc block for why address resolution is its own seam, and
 * `ProvisionalAggregateNotificationSubjectSource`'s doc block for why
 * `actorRef` can carry either shape this class distinguishes.
 *
 * Query builder on table names, never an `app/Domain/**` Eloquent model —
 * same Tier 2 platform-foundation dependency rule
 * `ProvisionalAggregateNotificationSubjectSource` documents for itself.
 */
final class EloquentRecipientAddressResolver implements RecipientAddressResolver
{
    public function emailFor(Recipient $recipient): ?string
    {
        $ref = $recipient->actorRef;

        if (is_string($ref) && str_starts_with($ref, ProvisionalAggregateNotificationSubjectSource::GUEST_ORDER_PARTY_PREFIX)) {
            $partyId = substr($ref, strlen(ProvisionalAggregateNotificationSubjectSource::GUEST_ORDER_PARTY_PREFIX));

            return DB::table('order_parties')->where('id', $partyId)->value('contact_email');
        }

        // Every other shape — a staff actor_identifier or an authenticated
        // customer's ownerRef — is a users.id.
        return DB::table('users')->where('id', $ref)->value('email');
    }
}
