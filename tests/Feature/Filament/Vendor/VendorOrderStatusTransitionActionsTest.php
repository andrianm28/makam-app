<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Vendor;

use App\Domain\Marketplace\MarketplaceAuditActions;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Filament\Vendor\Resources\VendorOrders\Pages\EditVendorOrder;
use App\Platform\Audit\Models\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MakesVendorOrderFixtures;
use Tests\TestCase;

/**
 * The six one-click status transitions the plan's Task 4.3 asks for
 * (accept / reject / process / sent-scheduled / complete / complained),
 * reached exactly the way a vendor reaches them: as header actions on the
 * order's edit page.
 *
 * Each assertion checks a side effect only `UpdateVendorOrderStatus` — the
 * Domain Action every action calls — produces: the `status` column move and
 * the `audit_events` row with `previous_state`/`new_state`. A transition
 * that flipped the column inline without auditing (Filament's default
 * `$record->update()`) would fail here; that is the wiring gap this file
 * exists to close. `UpdateVendorOrderStatusTest` proves the Action's own
 * contract; this file proves the UI calls it.
 *
 * Every actor is granted the order's vendor via `scope_assignments` first,
 * and that is not boilerplate: `ScopesToCurrentVendor` closes the resource
 * query to the granted vendor, so without the grant the edit page cannot
 * even mount the record (404). The grant also makes the test realistic —
 * this is the actor state a real vendor reaches `/vendor/orders/{id}/edit`
 * with. The denial side is already proven by `VendorPanelScopingTest`.
 */
final class VendorOrderStatusTransitionActionsTest extends TestCase
{
    use MakesVendorOrderFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Asset builds run in CI, never on a dev host (this repo's
        // CLAUDE.md), so `public/build/manifest.json` is absent here.
        $this->withoutVite();

        // A Livewire component test renders with no current panel, and URL
        // generation (`Resource::getUrl()`) falls back to the DEFAULT panel —
        // the admin panel — whose routes do not host this vendor resource
        // (`filament.admin.resources.orders.index` does not exist). Point the
        // generator at the vendor panel for the duration of the test.
        Filament::setCurrentPanel('vendor');
    }

    protected function tearDown(): void
    {
        // `FilamentManager` is a container singleton, so the current panel
        // leaks into the next test unless reset here.
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function transitionProvider(): iterable
    {
        yield 'accept' => ['accept', VendorProcessingStatus::DITERIMA_VENDOR, 'Pesanan diterima.'];
        yield 'reject' => ['reject', VendorProcessingStatus::DITOLAK_VENDOR, 'Pesanan ditolak.'];
        yield 'process' => ['process', VendorProcessingStatus::DIPROSES, 'Pesanan sedang diproses.'];
        yield 'sendSchedule' => ['sendSchedule', VendorProcessingStatus::DIKIRIM_OR_DIJADWALKAN, 'Pesanan dikirim / dijadwalkan.'];
        yield 'complete' => ['complete', VendorProcessingStatus::SELESAI, 'Pesanan selesai dikerjakan.'];
        yield 'complain' => ['complain', VendorProcessingStatus::KOMPLAIN, 'Pesanan ditandai komplain.'];
    }

    #[DataProvider('transitionProvider')]
    public function test_the_action_transitions_status_audits_and_notifies(
        string $actionName,
        string $targetStatus,
        string $notificationTitle,
    ): void {
        [$user, $order] = $this->vendorOrderForGrantedVendor();

        Livewire::test(EditVendorOrder::class, ['record' => $order->getRouteKey()])
            ->callAction($actionName)
            ->assertNotified($notificationTitle);

        $this->assertSame($targetStatus, $order->refresh()->status);

        $event = AuditEvent::query()
            ->where('action', MarketplaceAuditActions::ORDER_STATUS_CHANGED)
            ->where('subject_id', (string) $order->id)
            ->sole();
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('vendor', $event->actor_role);
        $this->assertSame(
            [
                'previous_state' => VendorProcessingStatus::MENUNGGU_VENDOR,
                'new_state' => $targetStatus,
            ],
            $event->metadata,
        );
    }

    public function test_each_action_is_hidden_once_its_target_status_is_already_set(): void
    {
        [, $order] = $this->vendorOrderForGrantedVendor(VendorProcessingStatus::DITERIMA_VENDOR);

        $component = Livewire::test(EditVendorOrder::class, ['record' => $order->getRouteKey()]);

        // The order is already accepted, so "Terima pesanan" would be a
        // no-op — hidden. The other moves are still meaningful — visible.
        $component->assertActionHidden('accept');
        $component->assertActionVisible('reject');
        $component->assertActionVisible('process');
        $component->assertActionVisible('sendSchedule');
        $component->assertActionVisible('complete');
        $component->assertActionVisible('complain');
    }

    public function test_the_form_save_writes_notes_alongside_the_status_through_the_action(): void
    {
        [, $order] = $this->vendorOrderForGrantedVendor();

        Livewire::test(EditVendorOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm(['status' => VendorProcessingStatus::DIPROSES, 'notes' => 'Mulai hari ini.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(VendorProcessingStatus::DIPROSES, $order->refresh()->status);
        $this->assertSame('Mulai hari ini.', $order->notes);

        $this->assertDatabaseHas('audit_events', [
            'action' => MarketplaceAuditActions::ORDER_STATUS_CHANGED,
            'subject_id' => (string) $order->id,
        ]);
    }
}
