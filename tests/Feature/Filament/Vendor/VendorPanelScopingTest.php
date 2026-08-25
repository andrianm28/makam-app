<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Vendor;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorAvailability;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\Models\VendorOrder;
use App\Domain\Marketplace\Models\VendorOrderEvidence;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Filament\Vendor\Concerns\ScopesToCurrentVendor;
use App\Filament\Vendor\Concerns\VendorScopedTablePage;
use App\Filament\Vendor\Pages\EvidenceList;
use App\Filament\Vendor\Pages\PayoutStatus;
use App\Filament\Vendor\Pages\TransactionHistory;
use App\Filament\Vendor\Resources\ServiceAreas\ServiceAreaResource;
use App\Filament\Vendor\Resources\VendorAvailabilities\VendorAvailabilityResource;
use App\Filament\Vendor\Resources\VendorListings\VendorListingResource;
use App\Filament\Vendor\Resources\VendorOrders\VendorOrderResource;
use App\Models\User;
use App\Platform\FinancialLedger\Models\VendorPayable;
use App\Platform\FinancialLedger\VendorPayableState;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The proof this lane exists for: a vendor sees its own records and nothing
 * else, enforced in the query rather than in the UI.
 *
 * Two kinds of assertion here, and both are needed:
 *
 * 1. BEHAVIOURAL — with two vendors' rows in the database and an actor granted
 *    exactly one of them, every surface in the panel returns only that
 *    vendor's rows. This is what the requirement actually says.
 * 2. STRUCTURAL — every Resource in the panel uses `ScopesToCurrentVendor` and
 *    every table Page uses `VendorScopedTablePage`, discovered by walking
 *    `app/Filament/Vendor/**` rather than from a hand-written list. The
 *    behavioural tests can only cover surfaces that exist today; the
 *    structural test is what fails CI when someone adds a seventh, unscoped
 *    surface next month. `Domain\Marketplace\Access\CurrentVendorScope`'s doc
 *    block names this test as the reason a per-surface mechanism is acceptable
 *    at all, so deleting it would silently remove that justification.
 */
final class VendorPanelScopingTest extends TestCase
{
    use RefreshDatabase;

    private const string PANEL_DIRECTORY = 'Filament/Vendor';

    private string $ownVendorId;

    private string $otherVendorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownVendorId = $this->makeVendorWithRecords('Vendor Sendiri');
        $this->otherVendorId = $this->makeVendorWithRecords('Vendor Lain');
    }

    // ---------------------------------------------------------------------
    // Behavioural: one granted vendor, two vendors' rows present
    // ---------------------------------------------------------------------

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function scopedSurfaceProvider(): iterable
    {
        yield 'products' => [VendorListingResource::class, 'resource'];
        yield 'orders' => [VendorOrderResource::class, 'resource'];
        yield 'calendar' => [VendorAvailabilityResource::class, 'resource'];
        yield 'service areas' => [ServiceAreaResource::class, 'resource'];
        yield 'transactions' => [TransactionHistory::class, 'page'];
        yield 'payouts' => [PayoutStatus::class, 'page'];
        yield 'evidence' => [EvidenceList::class, 'page'];
    }

    /**
     * @param  class-string  $surface
     */
    #[DataProvider('scopedSurfaceProvider')]
    public function test_surface_returns_only_the_granted_vendors_rows(string $surface, string $kind): void
    {
        $this->actingAsVendorGrantedTo($this->ownVendorId);

        $rows = $this->rowsFor($surface, $kind);

        $this->assertGreaterThan(0, $rows->count(), 'Fixture is vacuous: the granted vendor has no rows on this surface.');

        foreach ($rows as $row) {
            $this->assertSame(
                $this->ownVendorId,
                $this->vendorIdOf($row),
                "{$surface} returned a row belonging to another vendor.",
            );
        }
    }

    /**
     * @param  class-string  $surface
     */
    #[DataProvider('scopedSurfaceProvider')]
    public function test_surface_is_closed_for_an_actor_with_no_vendor_grant(string $surface, string $kind): void
    {
        // The deny-by-default direction. An authenticated actor holding no
        // vendor grant must see nothing, not everything — see
        // CurrentVendorScope's doc block on why empty closes the query.
        $this->actingAs(User::factory()->create());

        $this->assertSame(0, $this->rowsFor($surface, $kind)->count());
    }

    /**
     * @param  class-string  $surface
     */
    #[DataProvider('scopedSurfaceProvider')]
    public function test_surface_is_closed_for_a_guest(string $surface, string $kind): void
    {
        $this->assertSame(0, $this->rowsFor($surface, $kind)->count());
    }

    public function test_revoking_the_grant_closes_the_surfaces_again(): void
    {
        $user = $this->actingAsVendorGrantedTo($this->ownVendorId);

        $this->assertGreaterThan(
            0,
            $this->rowsFor(VendorListingResource::class, 'resource')->count(),
        );

        ScopeAssignment::query()
            ->where('actor_identifier', (string) $user->id)
            ->update(['revoked_at' => now()]);

        // A revoked grant must stop granting immediately. This is also the
        // assertion that pins the grant table — not `vendor_users` membership —
        // as the sole input: the vendor_users row created in the fixture is
        // untouched here and must not keep the surface open on its own.
        $this->refreshActorContext();

        $this->assertSame(
            0,
            $this->rowsFor(VendorListingResource::class, 'resource')->count(),
        );
    }

    // ---------------------------------------------------------------------
    // Structural: every surface in the panel is scoped, discovered not listed
    // ---------------------------------------------------------------------

    public function test_every_resource_in_the_panel_applies_the_vendor_scope(): void
    {
        $resources = $this->panelClassesThatAre(Resource::class);

        $this->assertNotEmpty($resources, 'Discovered no Resources — the discovery walk is broken, not the panel.');

        foreach ($resources as $resource) {
            $this->assertContains(
                ScopesToCurrentVendor::class,
                $this->traitsOf($resource),
                "{$resource} does not use ScopesToCurrentVendor, so its queries are not scoped to the acting vendor.",
            );
        }
    }

    public function test_every_table_page_in_the_panel_applies_the_vendor_scope(): void
    {
        // Resource list pages are excluded deliberately, not overlooked. They
        // are also `Page` subclasses that render a table, but their query comes
        // from their Resource's `getEloquentQuery()` — already asserted scoped
        // by the test above — so requiring the page-level trait on them would
        // demand the scope be applied twice. What is left after this filter is
        // the standalone panel pages, which own their own query and are the
        // only ones that can be unscoped independently of a Resource.
        $pages = array_filter(
            $this->panelClassesThatAre(Page::class),
            static fn (string $page): bool => is_subclass_of($page, HasTable::class)
                && ! is_subclass_of($page, \Filament\Resources\Pages\Page::class),
        );

        $this->assertNotEmpty($pages, 'Discovered no table Pages — the discovery walk is broken, not the panel.');

        foreach ($pages as $page) {
            $this->assertContains(
                VendorScopedTablePage::class,
                $this->traitsOf($page),
                "{$page} renders a table but does not use VendorScopedTablePage, so its query is not scoped.",
            );
        }
    }

    public function test_the_discovery_walk_sees_every_surface_the_behavioural_tests_cover(): void
    {
        // Guards the two structural tests above against silently passing on an
        // empty or truncated discovery result: every surface named by the data
        // provider must actually be found by walking the directory.
        $discovered = array_merge(
            $this->panelClassesThatAre(Resource::class),
            $this->panelClassesThatAre(Page::class),
        );

        foreach (self::scopedSurfaceProvider() as $case) {
            $this->assertContains($case[0], $discovered, "Discovery walk missed {$case[0]}.");
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param  class-string  $surface
     * @return Collection<int, Model>
     */
    private function rowsFor(string $surface, string $kind): Collection
    {
        return $kind === 'resource'
            ? $surface::getEloquentQuery()->get()
            : $surface::vendorScopedQuery()->get();
    }

    private function vendorIdOf(Model $row): ?string
    {
        // Evidence rows reach their vendor through the parent order.
        if ($row instanceof VendorOrderEvidence) {
            return $row->order?->vendor_id;
        }

        return $row->vendor_id;
    }

    private function actingAsVendorGrantedTo(string $vendorId): User
    {
        $user = User::factory()->create();

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => $vendorId,
        ]);

        $this->actingAs($user);
        $this->refreshActorContext();

        return $user;
    }

    /**
     * The actor context and its scope resolver are `scoped()` container
     * bindings resolved once per request. A test that changes the acting user
     * or a grant mid-method has to discard them, or it keeps reading the
     * context resolved before the change.
     */
    private function refreshActorContext(): void
    {
        $this->app->forgetScopedInstances();
    }

    /**
     * One vendor with one row on every surface the panel exposes, plus the
     * `vendor_users` membership row that must NOT by itself grant access.
     */
    private function makeVendorWithRecords(string $name): string
    {
        $vendor = Vendor::query()->create(['name' => $name, 'is_active' => true]);
        $vendorId = (string) $vendor->id;

        $productId = DB::table('products')->insertGetId([
            'code' => 'PRD-'.Str::random(8),
            'category' => 'KARANGAN_BUNGA',
            'name' => "Produk {$name}",
            'description' => 'Deskripsi uji.',
            'base_price_idr' => 100000,
            'price_version' => 1,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('vendor_users')->insert([
            'vendor_id' => $vendorId,
            'actor_identifier' => 'membership-only-'.Str::random(6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $listing = VendorListing::query()->create([
            'vendor_id' => $vendorId,
            'product_id' => $productId,
            'price_minor' => 150000,
            'availability_mode' => AvailabilityMode::STOCKED,
            'evidence_requirement' => EvidenceRequirement::PHOTO,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        ServiceArea::query()->create([
            'vendor_id' => $vendorId,
            'area_code' => 'AREA-'.Str::random(6),
            'area_label' => "Area {$name}",
            'delivery_fee_minor' => 25000,
            'is_active' => true,
        ]);

        VendorAvailability::query()->create([
            'vendor_id' => $vendorId,
            'available_date' => now()->addDay()->toDateString(),
            'capacity' => 3,
            'is_blocked' => false,
        ]);

        $order = VendorOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'vendor_id' => $vendorId,
            'listing_id' => $listing->id,
            'customer_name' => "Pelanggan {$name}",
            'customer_phone' => '081234567890',
            'customer_email' => 'pelanggan@example.test',
            'status' => VendorProcessingStatus::MENUNGGU_VENDOR,
        ]);

        VendorOrderEvidence::query()->create([
            'vendor_order_id' => $order->id,
            'file_path' => 'quarantine/'.Str::random(12).'.jpg',
            'evidence_type' => EvidenceRequirement::PHOTO,
            'uploaded_at' => now(),
        ]);

        VendorPayable::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'vendor_id' => $vendorId,
            'entity_ref' => 'order:'.$order->uuid,
            'source_type' => 'vendor_order',
            'source_id' => (string) $order->id,
            'amount_minor' => 150000,
            'state' => VendorPayableState::HELD,
        ]);

        return $vendorId;
    }

    /**
     * Every concrete class under `app/Filament/Vendor` that is a subclass of
     * `$base`. Walked from disk rather than listed, so a new file is covered
     * the moment it lands.
     *
     * @param  class-string  $base
     * @return list<class-string>
     */
    private function panelClassesThatAre(string $base): array
    {
        $found = [];

        $finder = (new Finder)
            ->files()
            ->in(app_path(self::PANEL_DIRECTORY))
            ->name('*.php');

        foreach ($finder as $file) {
            $class = $this->classNameFor($file);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf($base)) {
                continue;
            }

            $found[] = $class;
        }

        return $found;
    }

    /**
     * @return class-string|null
     */
    private function classNameFor(SplFileInfo $file): ?string
    {
        $relative = str_replace(
            [app_path().DIRECTORY_SEPARATOR, '.php'],
            '',
            $file->getRealPath(),
        );

        /** @var class-string $class */
        $class = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        return $class;
    }

    /**
     * Traits used by `$class` and by every parent of it — `class_uses()` alone
     * only reports the traits declared on the class itself, which would let a
     * scoped base class hide an unscoped child (and vice versa).
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private function traitsOf(string $class): array
    {
        $traits = [];

        for ($current = $class; $current !== false; $current = get_parent_class($current)) {
            $traits = [...$traits, ...array_values(class_uses($current) ?: [])];
        }

        return $traits;
    }
}
