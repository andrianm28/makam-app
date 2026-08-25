<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\ProductVariant;
use App\Domain\Marketplace\ProductCode;
use App\Domain\Marketplace\ProductVariantAuditActions;
use App\Filament\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Admin\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Proves the Task-6 behaviors of `VariantsRelationManager` on
 * `ProductResource`:
 *
 * 1. the master-data role gate holds at the relation-manager boundary —
 *    the `canViewForRecord()` override AND the action-level
 *    `->authorize()` closures, because a relation manager mounts without
 *    the resource's page-mount gate (`CanAuthorizeResourceAccess`);
 * 2. a variant create on a GRAVESTONE product succeeds inline and leaves
 *    its `PRODUCT_VARIANT_CREATED` audit row (via `Audit::wrap()` — AC4,
 *    same transaction as the row change);
 * 3. a variant create on a non-variant product (`FLOWER_*`) is HONEST:
 *    `ProductVariant::booted()`'s saving guard throws its
 *    `InvalidArgumentException` (the "does not carry variant axes"
 *    invariant), the transaction rolls back, and no audit row claims the
 *    refused create;
 * 4. an inline edit records its `PRODUCT_VARIANT_UPDATED` audit row.
 *
 * Fixtures come from the seed migrations — the nine canonical products
 * (asserted by `ProductCatalogueSeedTest`), with two example
 * `product_variants` rows per Batu Nisan product.
 */
final class ProductVariantRelationManagerTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        return $user;
    }

    private function productByCode(string $code): Product
    {
        $product = Product::findByCode($code);
        assert($product instanceof Product);

        return $product;
    }

    public function test_a_customer_cannot_interact_with_the_variants_relation_manager(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $product = $this->productByCode(ProductCode::GRAVESTONE_GRANITE);

        $component = Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ]);

        // The action-level `->authorize()` layer: neither create nor edit
        // is offered to an unauthorized actor (hidden, not merely disabled).
        $component
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit');

        // The `canViewForRecord()` layer: the guard aborts 403 on the next
        // wire request, so no interactive request — an action submission
        // included — can ever mutate anything.
        $component->call('refresh')->assertForbidden();
    }

    public function test_variant_create_on_a_gravestone_product_succeeds_with_an_audit_row(): void
    {
        $user = $this->admin();

        $product = $this->productByCode(ProductCode::GRAVESTONE_GRANITE);

        Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->assertCanSeeTableRecords($product->variants()->get())
            ->callTableAction('create', data: [
                'size' => '100 x 120 cm',
                'material' => 'Granit Hitam Premium',
                'color' => 'Hitam',
                'calligraphy_style' => null,
                'inscription_text_example' => null,
                'preview_image_path' => 'marketplace/gravestone-variants/placeholder-granit-premium.jpg',
                'sort_order' => 9,
            ])
            ->assertHasNoTableActionErrors();

        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('size', '100 x 120 cm')
            ->sole();

        $this->assertSame('Granit Hitam Premium', $variant->material);
        $this->assertSame(9, $variant->sort_order);

        $event = AuditEvent::query()
            ->where('action', ProductVariantAuditActions::CREATED)
            ->where('subject_id', (string) $variant->id)
            ->sole();

        $this->assertSame('product_variant', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
    }

    public function test_variant_create_on_a_non_variant_product_throws_the_models_invariant(): void
    {
        $this->admin();

        // FLOWER_BOARD is deliberately NOT on `ProductCode::GRAVESTONE_CODES`
        // (see `ProductCode::requiresVariants()`), so the model's saving
        // guard must reject the write — and the refusal must be an honest
        // `InvalidArgumentException` out of `ProductVariant::booted()`, not
        // a form error or a silent 500.
        $product = $this->productByCode(ProductCode::FLOWER_BOARD);

        try {
            Livewire::test(VariantsRelationManager::class, [
                'ownerRecord' => $product,
                'pageClass' => EditProduct::class,
            ])
                ->callTableAction('create', data: [
                    'size' => '60 x 80 cm',
                    'material' => 'Granit Hitam',
                    'color' => 'Hitam',
                    'sort_order' => 1,
                ]);

            $this->fail('Expected the model invariant to reject a variant on a non-variant product.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not carry variant axes', $e->getMessage());
        }

        $this->assertDatabaseMissing('product_variants', ['product_id' => $product->id]);

        // A refused create is not a mutation, so it must not leave an audit
        // row claiming one (`Audit::wrap()` rolls the whole transaction back).
        $this->assertDatabaseMissing('audit_events', ['action' => ProductVariantAuditActions::CREATED]);
    }

    public function test_variant_edit_records_an_audit_row(): void
    {
        $user = $this->admin();

        $product = $this->productByCode(ProductCode::GRAVESTONE_GRANITE);
        $variant = $product->variants()->firstOrFail();

        Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->callTableAction('edit', $variant, data: [
                'size' => '100 x 120 cm',
                'material' => $variant->material,
                'color' => $variant->color,
                'sort_order' => 7,
            ])
            ->assertHasNoTableActionErrors();

        $variant->refresh();
        $this->assertSame('100 x 120 cm', $variant->size);
        $this->assertSame(7, $variant->sort_order);

        $event = AuditEvent::query()
            ->where('action', ProductVariantAuditActions::UPDATED)
            ->where('subject_id', (string) $variant->id)
            ->sole();

        $this->assertSame('product_variant', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
    }
}
