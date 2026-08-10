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
}
