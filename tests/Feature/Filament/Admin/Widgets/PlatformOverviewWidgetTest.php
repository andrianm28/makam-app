<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Widgets;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\Actions\PublishFaqArticle;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqCategory;
use App\Domain\Marketplace\Models\Vendor;
use App\Filament\Admin\Widgets\PlatformOverviewWidget;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * ADM-001 AC1 — TPU/TPS, vendor and FAQ dashboard modules
 * (`PlatformOverviewWidget`). Proves the master-data gate (same shape as
 * `CemeteryResourceAccessTest`/`BookingOrderResourceAccessTest`) and that
 * the rendered numbers are real queries, not placeholders — every assertion
 * below re-derives its expected count from the same domain scopes the
 * widget itself calls.
 */
final class PlatformOverviewWidgetTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_and_bare_users_cannot_view_the_widget(): void
    {
        $this->assertFalse(PlatformOverviewWidget::canView());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(PlatformOverviewWidget::canView());
    }

    public function test_vendor_role_cannot_view_the_widget(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);
        $this->actingAs($user);

        $this->assertFalse(PlatformOverviewWidget::canView());
    }

    public function test_back_office_roles_can_view_the_widget(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(PlatformOverviewWidget::canView(), "role {$role} should view");
        }
    }

    public function test_it_renders_real_tpu_tps_vendor_and_faq_counts(): void
    {
        Cemetery::factory()->create(['type' => CemeteryType::TPU, 'publication_status' => CemeteryPublicationStatus::PUBLISHED]);
        Cemetery::factory()->create(['type' => CemeteryType::TPU, 'publication_status' => CemeteryPublicationStatus::PUBLISHED]);
        Cemetery::factory()->create(['type' => CemeteryType::TPU, 'publication_status' => CemeteryPublicationStatus::DRAFT]);
        Cemetery::factory()->create(['type' => CemeteryType::TPS, 'publication_status' => CemeteryPublicationStatus::PUBLISHED]);

        Vendor::query()->create(['name' => 'Vendor Aktif Satu', 'is_active' => true]);
        Vendor::query()->create(['name' => 'Vendor Aktif Dua', 'is_active' => true]);
        Vendor::query()->create(['name' => 'Vendor Nonaktif', 'is_active' => false]);

        $category = FaqCategory::findByCode(FaqCategoryCode::CARA_MEMESAN);
        $this->assertNotNull($category);

        $create = new CreateFaqArticleDraft;
        $draft = $create(
            categoryId: $category->id,
            title: 'Judul artikel dasbor uji',
            slug: 'judul-artikel-dasbor-uji-widget',
            summary: 'Ringkasan artikel dasbor uji widget.',
            body: 'Isi artikel dasbor uji widget.',
            actorReference: 1,
        );
        (new PublishFaqArticle)($draft, actorReference: 1);

        $expectedTpuTotal = Cemetery::query()->ofType(CemeteryType::TPU)->count();
        $expectedTpsTotal = Cemetery::query()->ofType(CemeteryType::TPS)->count();
        $expectedVendorActive = Vendor::query()->active()->count();
        $expectedVendorTotal = Vendor::query()->count();
        $expectedFaqPublished = FaqArticle::query()->published()->count();

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $component = Livewire::test(PlatformOverviewWidget::class);

        $component->assertSee((string) $expectedTpuTotal)
            ->assertSee((string) $expectedTpsTotal)
            ->assertSee((string) $expectedVendorActive)
            ->assertSee((string) $expectedFaqPublished)
            ->assertSee("dari {$expectedVendorTotal} total vendor");
    }
}
