<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Filament\Support\OrderViewUrl;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The order actions are shared by `/admin` and `/operator`; a hardcoded
 * `filament.admin.*` redirect would land a cemetery_operator on a panel
 * `AdminPanelAccessPolicy` refuses. These assertions pin that the redirect
 * target follows the panel the action was invoked from.
 */
final class OrderViewUrlTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);
    }

    public function test_it_resolves_the_admin_panels_url_inside_the_admin_panel(): void
    {
        Filament::setCurrentPanel('admin');

        $url = OrderViewUrl::for($this->order);

        $this->assertStringContainsString('/admin/pesanan-pemakaman/'.$this->order->getKey(), $url);
    }

    public function test_it_resolves_the_operator_panels_url_inside_the_operator_panel(): void
    {
        Filament::setCurrentPanel('operator');

        $url = OrderViewUrl::for($this->order);

        $this->assertStringContainsString('/operator/pesanan/'.$this->order->getKey(), $url);
        $this->assertStringNotContainsString('/admin/', $url);
    }

    public function test_it_falls_back_to_the_admin_panel_when_no_panel_is_current(): void
    {
        Filament::setCurrentPanel(null);

        $url = OrderViewUrl::for($this->order);

        $this->assertStringContainsString('/admin/pesanan-pemakaman/'.$this->order->getKey(), $url);
    }

    public function test_it_falls_back_to_the_admin_panel_when_the_current_panel_has_no_order_resource(): void
    {
        // The vendor panel registers no resource whose model is Order, so
        // getModelResource() returns null and the fallback must engage
        // rather than throwing.
        Filament::setCurrentPanel('vendor');

        $url = OrderViewUrl::for($this->order);

        $this->assertStringContainsString('/admin/pesanan-pemakaman/'.$this->order->getKey(), $url);
    }
}
