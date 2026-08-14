<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Domain\Marketplace\MarketplaceProductCategory;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\ProductAuditActions;
use App\Domain\Marketplace\ProductCode;
use App\Filament\Admin\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Admin\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Admin\Resources\ProductResource\ProductResource;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The admin resource for `App\Domain\Marketplace\Models\Product` — the
 * admin-managed-master-data design spec's ProductResource. Proves, per the
 * plan's Task 5:
 *
 *  - the role gate (four back-office roles yes, bare customer no, guest no)
 *    via `ProductResource::canAccess()` and the Livewire/page boundaries;
 *  - `code`/`category` are canonical and read-only on edit;
 *  - a base-price edit bumps `price_version` by exactly 1 (the column's
 *    documented "new cut of the definition" semantics — the backfill
 *    migration left every seeded row at `2`), and a non-price edit does not;
 *  - create/update go through `Audit::wrap()`/`Audit::record()` with the
 *    mandatory recorded reason that `ProductAuditActions::UPDATED`'s
 *    `SensitiveActions` membership requires.
 */
final class ProductResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    // -----------------------------------------------------------------------
    // Access gate
    // -----------------------------------------------------------------------

    public function test_a_guest_cannot_access_the_product_resource(): void
    {
        $this->assertFalse(ProductResource::canAccess());
    }

    public function test_an_authenticated_customer_cannot_access_the_product_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $this->assertFalse(ProductResource::canAccess());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function backOfficeRoleProvider(): array
    {
        return [
            'admin' => [ActorRole::ADMIN],
            'restricted admin' => [ActorRole::RESTRICTED_ADMIN],
            'operator' => [ActorRole::OPERATOR],
            'finance' => [ActorRole::FINANCE],
        ];
    }

    #[DataProvider('backOfficeRoleProvider')]
    public function test_each_back_office_role_can_access_the_product_resource(string $role): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, $role);
        $this->actingAs($user);

        $this->assertTrue(ProductResource::canAccess());
    }

    public function test_a_customer_is_forbidden_from_the_list_page_component(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $component = Livewire::test(ListProducts::class);

        if ($component->instance() === null) {
            $component->assertForbidden();

            return;
        }

        $this->fail('A roleless customer must not mount the product list page.');
    }

    public function test_an_admin_can_open_the_product_list_route(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $this->get('/admin/products')->assertOk();
    }

    public function test_get_authorization_response_refuses_a_customer_and_allows_a_back_office_role(): void
    {
        // `getAuthorizationResponse()` is the resource's row-ability gate
        // (every `getEditAuthorizationResponse()`/... predicate routes
        // through it). Without the override, Filament's no-policy path
        // would FAIL OPEN here; the override must refuse a bare customer
        // exactly like `canAccess()` does.
        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        assert($product instanceof Product);

        $customer = User::factory()->create();
        $this->actingAs($customer);
        $this->forgetResolvedActorContext();

        $denied = ProductResource::getAuthorizationResponse('update', $product);
        $this->assertTrue($denied->denied());

        $admin = User::factory()->create();
        $this->grantRoleTo($admin, ActorRole::ADMIN);
        $this->actingAs($admin);

        $allowed = ProductResource::getAuthorizationResponse('update', $product);
        $this->assertTrue($allowed->allowed());
    }

    // -----------------------------------------------------------------------
    // Canonical fields are read-only
    // -----------------------------------------------------------------------

    public function test_product_code_and_category_are_read_only_on_the_edit_page(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        assert($product instanceof Product);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldExists('code', fn (Select $field): bool => $field->isDisabled())
            ->assertFormFieldExists('category', fn (Select $field): bool => $field->isDisabled());
    }

    // -----------------------------------------------------------------------
    // Price-version semantics
    // -----------------------------------------------------------------------

    public function test_editing_the_base_price_increments_the_price_version(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        assert($product instanceof Product);
        $oldVersion = $product->price_version;
        $oldBasePrice = $product->base_price_idr;

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'base_price_idr' => $oldBasePrice + 1_000_000,
                'reason' => 'Menyesuaikan harga pasar.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame($oldVersion + 1, $product->price_version);
        $this->assertSame($oldBasePrice + 1_000_000, $product->base_price_idr);
    }

    public function test_an_edit_that_keeps_the_price_does_not_increment_the_version(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        assert($product instanceof Product);
        $oldVersion = $product->price_version;

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'vendor_name' => 'CV Karya Baru',
                'reason' => 'Mengganti nama vendor.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($oldVersion, $product->refresh()->price_version);
    }

    // -----------------------------------------------------------------------
    // Audit on create/update
    // -----------------------------------------------------------------------

    public function test_creating_a_product_records_an_audit_event(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        // All nine canonical codes exist after the seed migrations, so the
        // only way to exercise a real create is to first remove the row the
        // form then re-creates.
        $seeded = Product::findByCode(ProductCode::FLOWER_BOARD);
        assert($seeded instanceof Product);
        $seeded->delete();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'code' => ProductCode::FLOWER_BOARD,
                'category' => MarketplaceProductCategory::FLOWERS,
                'name' => 'Karangan Bunga Duka',
                'description' => 'Karangan bunga duka untuk upacara pemakaman.',
                'vendor_name' => 'CV Kembang Sepatu',
                'base_price_idr' => 1_250_000,
                'is_active' => true,
                'sort_order' => 10,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        assert($product instanceof Product);
        $this->assertSame(1, $product->price_version);

        $event = AuditEvent::query()
            ->where('action', ProductAuditActions::CREATED)
            ->where('subject_id', (string) $product->id)
            ->sole();

        $this->assertSame('product', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
        $this->assertSame('1', $event->subject_version);
    }

    public function test_updating_a_product_records_an_audit_event_with_the_reason(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        assert($product instanceof Product);
        $oldVersion = $product->price_version;

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'base_price_idr' => $product->base_price_idr + 250_000,
                'reason' => 'Penyesuaian harga dari vendor.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $event = AuditEvent::query()
            ->where('action', ProductAuditActions::UPDATED)
            ->where('subject_id', (string) $product->id)
            ->sole();

        $this->assertSame('product', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
        $this->assertSame((string) ($oldVersion + 1), $event->subject_version);
        $this->assertSame('Penyesuaian harga dari vendor.', $event->reason);
    }

    public function test_an_edit_without_a_reason_fails_validation_and_changes_nothing(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        assert($product instanceof Product);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['base_price_idr' => $product->base_price_idr + 1])
            ->call('save')
            ->assertHasFormErrors(['reason' => 'required']);

        $this->assertSame(2, $product->refresh()->price_version);
        $this->assertDatabaseMissing('audit_events', ['action' => ProductAuditActions::UPDATED]);
    }
}
