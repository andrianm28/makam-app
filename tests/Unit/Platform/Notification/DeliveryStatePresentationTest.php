<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\DeliveryState;
use Tests\TestCase;

final class DeliveryStatePresentationTest extends TestCase
{
    public function test_delivery_states_keep_pending_success_neutral_and_failure_visuals_distinct(): void
    {
        $this->assertSame(['intent' => 'pending', 'label' => 'Sedang dikirim'], DeliveryState::Queued->presentation());
        $this->assertSame(['intent' => 'success', 'label' => 'Terkirim'], DeliveryState::Sent->presentation());
        $this->assertSame(['intent' => 'success', 'label' => 'Terkirim'], DeliveryState::Delivered->presentation());
        $this->assertSame(['intent' => 'neutral', 'label' => 'WhatsApp belum tersedia'], DeliveryState::Unavailable->presentation());
        $this->assertSame(['intent' => 'danger', 'label' => 'Gagal mengirim'], DeliveryState::Failed->presentation());
    }

    /**
     * Task 7a slice 3 fix: UNAVAILABLE is recorded for causes other than the
     * WA-gate closure (e.g. a missing template version, on any channel
     * including EMAIL — Actions\DispatchNotification, D4/AC2/AC12). Before
     * this fix, every UNAVAILABLE row rendered the WA-gate-specific label
     * regardless of cause, so an EMAIL row could render the
     * self-contradicting "Email · WhatsApp belum tersedia". The row's own
     * `failure_message` (null only for the genuine WA-gate case) picks the
     * label; a non-null cause always renders the channel-neutral label,
     * never the raw `failure_message` text itself.
     */
    public function test_unavailable_only_shows_the_whatsapp_gate_label_for_the_whatsapp_gate_cause(): void
    {
        $this->assertSame(
            ['intent' => 'neutral', 'label' => 'WhatsApp belum tersedia'],
            DeliveryState::Unavailable->presentation(null)
        );

        $this->assertSame(
            ['intent' => 'neutral', 'label' => 'Notifikasi tidak tersedia'],
            DeliveryState::Unavailable->presentation('NOTIFICATION_TEMPLATE_UNAVAILABLE')
        );

        $this->assertSame(
            ['intent' => 'neutral', 'label' => 'Notifikasi tidak tersedia'],
            DeliveryState::Unavailable->presentation('NOTIFICATION_CHANNEL_UNAVAILABLE')
        );
    }
}
