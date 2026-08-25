<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Filament\Admin\Resources\CemeteryResource;
use App\Filament\Admin\Resources\CemeteryResource\Pages\CreateCemetery;
use App\Filament\Admin\Resources\CemeteryResource\Pages\EditCemetery;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Proves the CemeteryResource create/edit forms: an authorized admin can
 * create a cemetery (row + audit row), and the slug is immutable on edit.
 *
 * ---------------------------------------------------------------------------
 * The audit row is what proves the write went through the audited path
 * ---------------------------------------------------------------------------
 * Same reasoning as `tests/Feature/Filament/Admin/Faq/CreateFaqArticleTest`:
 * `Audit::record()` is called from the page's `afterCreate()`/`afterUpdate()`
 * hooks, which run inside Filament's own database transaction
 * (`CreateRecord::create()` / `EditRecord::save()` begin one), so asserting
 * both the row AND its `audit_events` entry proves the paired mutation+audit
 * rather than a bare model save.
 *
 * ---------------------------------------------------------------------------
 * Every actor here is granted `ActorRole::ADMIN` first — not boilerplate
 * ---------------------------------------------------------------------------
 * Same reasoning as the FAQ resource tests: `CemeteryResource::canAccess()`
 * refuses every actor without one of the four back-office roles, so the
 * grant is what lets these tests reach their subject at all. The refusal
 * side is proved in `CemeteryResourceAccessTest`.
 */
final class CemeteryResourceCrudTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function validCreateData(): array
    {
        return [
            'name' => 'TPU Contoh Baru',
            'slug' => 'tpu-contoh-baru',
            'type' => CemeteryType::TPU,
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh Kota Jakarta No. 99',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'facilities' => [
                ['facilities' => 'Area Parkir'],
                ['facilities' => 'Toilet Umum'],
            ],
            'price_min' => 3_500_000,
            'price_max' => 6_300_000,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
        ];
    }

    public function test_an_authorized_admin_can_create_a_cemetery_with_an_audit_trail(): void
    {
        $user = $this->admin();

        Livewire::test(CreateCemetery::class)
            ->fillForm($this->validCreateData())
            ->call('create')
            ->assertHasNoFormErrors();

        $cemetery = Cemetery::query()->where('slug', 'tpu-contoh-baru')->sole();

        $this->assertSame('TPU Contoh Baru', $cemetery->name);
        $this->assertSame(CemeteryType::TPU, $cemetery->type);
        $this->assertSame(LaunchCityCode::JAKARTA, $cemetery->city);
        $this->assertSame(CemeteryPublicationStatus::PUBLISHED, $cemetery->publication_status);
        $this->assertSame(['Area Parkir', 'Toilet Umum'], $cemetery->facilities);
        $this->assertSame('3500000.00', $cemetery->price_min);
        $this->assertSame('6300000.00', $cemetery->price_max);

        $event = AuditEvent::query()
            ->where('action', 'CEMETERY_CREATED')
            ->where('subject_id', (string) $cemetery->id)
            ->sole();

        $this->assertSame('cemetery', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
        $this->assertSame(['new_state' => CemeteryPublicationStatus::PUBLISHED], $event->metadata);
    }

    public function test_a_cemetery_slug_is_disabled_and_immutable_on_edit(): void
    {
        $user = $this->admin();

        $cemetery = Cemetery::query()->create([
            'name' => 'TPU Lama',
            'slug' => 'tpu-lama',
            'type' => CemeteryType::TPS,
            'city' => LaunchCityCode::BEKASI,
            'address' => 'Jl. Contoh Kota Bekasi No. 1',
            'publication_status' => CemeteryPublicationStatus::DRAFT,
        ]);

        Livewire::test(EditCemetery::class, ['record' => $cemetery->getRouteKey()])
            ->assertFormFieldIsDisabled('slug')
            ->fillForm([
                'slug' => 'tpu-lama-ganti',
                'name' => 'TPU Lama Diubah',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $cemetery->refresh();

        $this->assertSame('tpu-lama', $cemetery->slug);
        $this->assertSame('TPU Lama Diubah', $cemetery->name);

        $event = AuditEvent::query()
            ->where('action', 'CEMETERY_UPDATED')
            ->where('subject_id', (string) $cemetery->id)
            ->sole();
        $this->assertSame((string) $user->id, $event->actor_ref);
    }

    public function test_a_duplicate_slug_fails_create_validation(): void
    {
        $user = $this->admin();

        $existing = Cemetery::query()->create([
            'name' => 'TPU Sudah Ada',
            'slug' => 'tpu-sudah-ada',
            'type' => CemeteryType::TPU,
            'city' => LaunchCityCode::DEPOK,
            'address' => 'Jl. Contoh Kota Depok No. 1',
            'publication_status' => CemeteryPublicationStatus::DRAFT,
        ]);

        Livewire::test(CreateCemetery::class)
            ->fillForm([
                ...$this->validCreateData(),
                'slug' => 'tpu-sudah-ada',
            ])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);

        // The pre-existing fixture row survives; no second row with the
        // duplicated slug was created. (Not `assertDatabaseCount('cemeteries', 1)`:
        // RefreshDatabase runs the migrations, which seed the ten example
        // cemeteries.)
        $this->assertSame(1, Cemetery::query()->where('slug', 'tpu-sudah-ada')->count());
    }
}
