<?php

declare(strict_types=1);

namespace App\Platform\Notification\Channels;

use App\Platform\Notification\Contracts\Channel;
use App\Platform\Notification\Contracts\RecipientAddressResolver;
use App\Platform\Notification\DeliveryResult;
use App\Platform\Notification\DeliveryState;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\NotificationVariableResolver;
use App\Platform\Notification\Recipient;
use App\Platform\Notification\RecipientSet;
use App\Platform\Notification\TemplateRenderer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * The first real (non-development) `Contracts\Channel` implementation —
 * added for public-beta readiness alongside the `order`/`quote` additions
 * to `ProvisionalAggregateNotificationSubjectSource`, which are what give
 * this channel any recipient to actually address.
 *
 * ---------------------------------------------------------------------------
 * Why this needed a new seam, not just a new class
 * ---------------------------------------------------------------------------
 * `Recipient::actorRef` is an opaque reference — a `users.id` for a staff
 * role, or (since the `order`/`quote` addition) a
 * `guest_order_party:{id}` string for an anonymous customer. Neither format
 * is an email address, so this class does not resolve one itself; it reads
 * it from `Contracts\RecipientAddressResolver` — see that contract's doc
 * block for why address resolution is channel-agnostic and lives in its own
 * seam rather than as a method here.
 *
 * ---------------------------------------------------------------------------
 * `NotificationRecipient` uses ONE address per delivery, deliberately
 * ---------------------------------------------------------------------------
 * `$recipients` (`RecipientSet`) is the resolved set for the WHOLE outbox
 * event; `Actions\DispatchNotification` already writes one
 * `notification_deliveries` row per `(recipient, channel)` pair before
 * `Channel::send()` is ever called (`recordRecipientsAndDeliveries()`). This
 * method therefore addresses the mail to the ONE recipient
 * `$delivery->recipient_ref` names — matching that ref back to its
 * `Recipient` in `$recipients` — never every recipient in the set. Sending
 * to the whole set here would double-send: once per delivery row, each time
 * to everyone.
 *
 * ---------------------------------------------------------------------------
 * Rendering
 * ---------------------------------------------------------------------------
 * Renders for itself, per the `Contracts\Channel` contract's doc block, by
 * resolving its own variable bag first:
 * `render($version, $this->variables->forDelivery($delivery, $version))`.
 * `forDelivery()` is the channel-shaped entry point on
 * `NotificationVariableResolver`: this class holds only the
 * `NotificationDelivery`, so the resolver walks `$delivery->event_id` back
 * to the outbox row that carries the payload, rebuilds the bag
 * `Actions\DispatchNotification` built on the fresh-event path, and
 * restricts it to this version's allowlist.
 *
 * This doc block used to claim the opposite — that `render($version, [])`
 * "is the only call that can ever succeed against current data", because
 * every seeded template version had an empty `variable_allowlist`. It no
 * longer does: the version-2 and version-3 template migrations ship
 * allowlisted variables and bodies that reference them with real
 * `{{ name }}` placeholders. Against those, `[]` is the one call that can
 * never succeed — `TemplateRenderer::render()` throws "Notification
 * variable [x] was not provided." for any name the body references and the
 * caller omits. `Jobs\SendNotificationChannelJob` catches anything thrown
 * out of `send()`, records it as `DeliveryResult::CHANNEL_SEND_FAILED` in
 * the retryable `Failed` state, and re-queues it on the backoff — so the
 * throw does not fail one unlucky delivery, it burns the retry budget of
 * every single send of that template. Resolving the bag is strictly safer and never
 * narrower: for a version whose allowlist is empty the resolver restricts
 * the bag back down to exactly `[]`, so the seeded version-1 templates
 * render byte-identically to before.
 *
 * ---------------------------------------------------------------------------
 * Failure classification
 * ---------------------------------------------------------------------------
 * An unresolvable address (no recipient match, or the address resolver
 * found nothing) is `Unavailable`, not `Failed` — there was nothing to
 * attempt, not a rejected attempt, mirroring how a closed WA gate is
 * recorded (`Actions\DispatchNotification`'s AC12 note). A transport
 * exception is `Failed` with `retryable: true` so `Jobs\
 * RetryFailedDeliveryJob`'s existing backoff engages — mail transports
 * throw `TransportExceptionInterface` for exactly the transient cases
 * (connection refused, timeout, provider 4xx/5xx) retry exists for.
 */
final class MailChannel implements Channel
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly RecipientAddressResolver $addresses,
        private readonly NotificationVariableResolver $variables,
    ) {}

    public function send(
        NotificationDelivery $delivery,
        NotificationTemplateVersion $version,
        RecipientSet $recipients,
    ): DeliveryResult {
        $recipient = $this->matchingRecipient($delivery, $recipients);

        if ($recipient === null) {
            return new DeliveryResult(
                DeliveryState::Unavailable,
                message: 'No recipient in the resolved set matched this delivery.',
                retryable: false,
            );
        }

        $email = $this->addresses->emailFor($recipient);

        if ($email === null || $email === '') {
            return new DeliveryResult(
                DeliveryState::Unavailable,
                message: 'No email address could be resolved for this recipient.',
                retryable: false,
            );
        }

        $rendered = $this->renderer->render($version, $this->variables->forDelivery($delivery, $version));
        $subject = $rendered['subject'] ?? 'Notifikasi Makam.co.id';
        $body = $rendered['body'];

        try {
            Mail::to($email)->send(new RenderedNotificationMailable($subject, $body));
        } catch (TransportExceptionInterface $exception) {
            // AGENTS.md §Observability: no address, no rendered content —
            // only what class of failure this was.
            Log::warning('MailChannel: transient transport failure sending a notification.', [
                'exception' => $exception::class,
            ]);

            return new DeliveryResult(DeliveryState::Failed, message: DeliveryResult::CHANNEL_SEND_FAILED, retryable: true);
        } catch (Throwable $exception) {
            Log::error('MailChannel: unexpected failure sending a notification.', [
                'exception' => $exception::class,
            ]);

            return new DeliveryResult(DeliveryState::Failed, message: DeliveryResult::CHANNEL_SEND_FAILED, retryable: false);
        }

        $providerRef = 'mail-'.substr(hash('sha256', (string) ($delivery->provider_idempotency_key ?? $delivery->getKey())), 0, 16);

        return new DeliveryResult(DeliveryState::Sent, providerRef: $providerRef);
    }

    private function matchingRecipient(NotificationDelivery $delivery, RecipientSet $recipients): ?Recipient
    {
        foreach ($recipients as $recipient) {
            if ((string) $recipient->actorRef === $delivery->recipient_ref) {
                return $recipient;
            }
        }

        return null;
    }
}
