<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\Channels\LogChannel;
use App\Platform\Notification\Channels\NullChannel;
use App\Platform\Notification\DeliveryState;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\RecipientSet;
use App\Platform\Notification\TemplateRenderer;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class NotificationChannelsTest extends TestCase
{
    /**
     * NOTIF-07, 07 Sep 2026: the LOG channel must never report `Sent` —
     * nothing actually left the system, so it now reports `Unavailable`
     * with a non-null `failure_message` (`LogChannel::LOG_ONLY_MESSAGE`),
     * which `DeliveryState::presentation()` renders as the neutral
     * "Notifikasi tidak tersedia" — never "Terkirim".
     */
    public function test_log_channel_logs_rendered_body_and_returns_unavailable_never_sent(): void
    {
        Log::spy();
        $delivery = new NotificationDelivery;
        $delivery->forceFill([
            'id' => 42,
            'channel' => 'EMAIL',
            'provider_idempotency_key' => str_repeat('a', 64),
        ]);
        $version = new NotificationTemplateVersion;
        $version->forceFill([
            'subject' => 'Notification subject',
            'body' => 'Notification body',
            'variable_allowlist' => [],
            'restricted_fields' => [],
        ]);

        $result = (new LogChannel(new TemplateRenderer))->send($delivery, $version, RecipientSet::empty());

        $this->assertSame(DeliveryState::Unavailable, $result->state);
        $this->assertSame(LogChannel::LOG_ONLY_MESSAGE, $result->message);
        $this->assertStringStartsWith('log-', (string) $result->providerRef);
        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Notification written to development log.'
                && $context['body'] === 'Notification body'
                && $context['channel'] === 'EMAIL';
        });

        // The finding's own concrete target: a LOG-channel delivery must
        // present as the neutral "Notifikasi tidak tersedia", never
        // "Terkirim" — the WA-gate branch of `Unavailable` requires a NULL
        // `failure_message` to render its own copy instead, so a non-null
        // message here must take the OTHER branch.
        $presentation = $result->state->presentation($result->message);
        $this->assertSame('Notifikasi tidak tersedia', $presentation['label']);
        $this->assertNotSame('Terkirim', $presentation['label']);
    }

    public function test_null_channel_returns_unavailable_without_claiming_sent(): void
    {
        $result = (new NullChannel)->send(
            new NotificationDelivery,
            new NotificationTemplateVersion,
            RecipientSet::empty(),
        );

        $this->assertSame(DeliveryState::Unavailable, $result->state);
        $this->assertNull($result->providerRef);
        $this->assertFalse($result->retryable);
    }
}
