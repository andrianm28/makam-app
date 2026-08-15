<?php

declare(strict_types=1);

namespace Tests\Feature\Renewal;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\ExpireRenewal;
use App\Domain\Renewal\Actions\MarkRenewalPaidExternally;
use App\Domain\Renewal\Exceptions\RenewalAlreadySettledException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminRenewalActionsTest extends TestCase
{
    use RefreshDatabase;

    private function renewal(string $status): Renewal
    {
        $grave = GraveRecord::factory()->create();

        return Renewal::query()->create([
            'grave_record_id' => $grave->getKey(),
            'target_due_period' => '2026-12-01',
            'reference' => 'EXT-'.strtoupper(substr(uniqid(), 0, 8)),
            'status' => $status,
            'source' => 'online',
        ]);
    }

    public function test_mark_paid_externally_records_evidence(): void
    {
        $renewal = $this->renewal(RenewalStatus::MENUNGGU_PEMBAYARAN);
        app(MarkRenewalPaidExternally::class)($renewal, 'Bukti transfer BCA #123', 'Pelunasan di kasir', 'user:1', 'finance');

        $this->assertSame(RenewalStatus::DIBAYAR, $renewal->status);
        $this->assertNotNull($renewal->settled_at);
        $this->assertDatabaseHas('renewal_external_markings', ['renewal_id' => $renewal->getKey()]);
        $this->assertDatabaseHas('audit_events', ['action' => 'RENEWAL_EXTERNAL_MARKING']);
    }

    public function test_mark_paid_refuses_settled_renewal(): void
    {
        $renewal = $this->renewal(RenewalStatus::DIBAYAR);
        $this->expectException(RenewalAlreadySettledException::class);
        app(MarkRenewalPaidExternally::class)($renewal, 'x', 'y', 'user:1', 'finance');
    }

    public function test_expire_transitions_to_kedaluwarsa(): void
    {
        $renewal = $this->renewal(RenewalStatus::MENUNGGU_PEMBAYARAN);
        app(ExpireRenewal::class)($renewal, 'user:1', 'operator');
        $this->assertSame(RenewalStatus::KEDALUWARSA, $renewal->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'RENEWAL_EXPIRED']);
    }
}
