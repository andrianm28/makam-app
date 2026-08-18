<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\Channels\MailChannel;
use App\Platform\Notification\Channels\RenderedNotificationMailable;
use App\Platform\Notification\Contracts\RecipientAddressResolver;
use App\Platform\Notification\DeliveryState;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\Recipient;
use App\Platform\Notification\RecipientRole;
use App\Platform\Notification\RecipientSet;
use App\Platform\Notification\TemplateRenderer;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * `Channels\MailChannel` — see its own doc block for the recipient-matching
 * and address-resolution seams this exercises.
 */
final class MailChannelTest extends TestCase
{
    private function recipient(string $actorRef = '7'): Recipient
    {
        return new Recipient(
            actorRef: $actorRef,
            actorRole: RecipientRole::CUSTOMER,
            scopeEntityType: null,
            scopeEntityId: null,
        );
    }

    private function delivery(string $recipientRef = '7'): NotificationDelivery
    {
        $delivery = new NotificationDelivery;
        $delivery->forceFill([
            'id' => 99,
            'recipient_ref' => $recipientRef,
            'channel' => 'EMAIL',
            'provider_idempotency_key' => str_repeat('a', 64),
        ]);

        return $delivery;
    }

    private function version(): NotificationTemplateVersion
    {
        $version = new NotificationTemplateVersion;
        $version->forceFill([
            'subject' => 'Konfirmasi Pesanan',
            'body' => 'Pesanan Anda telah diterima.',
            'variable_allowlist' => [],
            'restricted_fields' => [],
        ]);

        return $version;
    }

    public function test_it_sends_to_the_resolved_address_and_returns_sent(): void
    {
        Mail::fake();

        $addresses = new class implements RecipientAddressResolver
        {
            public function emailFor(Recipient $recipient): ?string
            {
                return 'customer@example.test';
            }
        };

        $channel = new MailChannel(new TemplateRenderer, $addresses);

        $result = $channel->send($this->delivery(), $this->version(), new RecipientSet([$this->recipient()]));

        $this->assertSame(DeliveryState::Sent, $result->state);
        $this->assertStringStartsWith('mail-', (string) $result->providerRef);

        Mail::assertSent(function (RenderedNotificationMailable $mailable) {
            return $mailable->hasTo('customer@example.test');
        });
    }

    public function test_it_reports_unavailable_when_no_recipient_in_the_set_matches_the_delivery(): void
    {
        Mail::fake();

        $addresses = new class implements RecipientAddressResolver
        {
            public function emailFor(Recipient $recipient): ?string
            {
                throw new \LogicException('emailFor() must not be called when no recipient matched.');
            }
        };

        $channel = new MailChannel(new TemplateRenderer, $addresses);

        // The delivery names recipient_ref "7"; the set carries "999" — no match.
        $result = $channel->send($this->delivery('7'), $this->version(), new RecipientSet([$this->recipient('999')]));

        $this->assertSame(DeliveryState::Unavailable, $result->state);
        $this->assertFalse($result->retryable);
        Mail::assertNothingSent();
    }

    public function test_it_reports_unavailable_when_no_address_resolves(): void
    {
        Mail::fake();

        $addresses = new class implements RecipientAddressResolver
        {
            public function emailFor(Recipient $recipient): ?string
            {
                return null;
            }
        };

        $channel = new MailChannel(new TemplateRenderer, $addresses);

        $result = $channel->send($this->delivery(), $this->version(), new RecipientSet([$this->recipient()]));

        $this->assertSame(DeliveryState::Unavailable, $result->state);
        $this->assertFalse($result->retryable);
        Mail::assertNothingSent();
    }

    public function test_a_transient_transport_failure_is_reported_failed_and_retryable(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('connection refused'));

        $addresses = new class implements RecipientAddressResolver
        {
            public function emailFor(Recipient $recipient): ?string
            {
                return 'customer@example.test';
            }
        };

        $channel = new MailChannel(new TemplateRenderer, $addresses);

        $result = $channel->send($this->delivery(), $this->version(), new RecipientSet([$this->recipient()]));

        $this->assertSame(DeliveryState::Failed, $result->state);
        $this->assertTrue($result->retryable);
    }

    public function test_never_sends_to_the_whole_recipient_set_only_the_delivery_owning_recipient(): void
    {
        Mail::fake();

        $addresses = new class implements RecipientAddressResolver
        {
            public function emailFor(Recipient $recipient): ?string
            {
                return $recipient->actorRef.'@example.test';
            }
        };

        $channel = new MailChannel(new TemplateRenderer, $addresses);

        $set = new RecipientSet([$this->recipient('7'), $this->recipient('8'), $this->recipient('9')]);

        $channel->send($this->delivery('8'), $this->version(), $set);

        Mail::assertSent(function (RenderedNotificationMailable $mailable) {
            return $mailable->hasTo('8@example.test');
        });
        Mail::assertSentCount(1);
    }
}
