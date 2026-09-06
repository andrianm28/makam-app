<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Memorial\Actions\CreateMemorialProfile;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Filament\Admin\Resources\MemorialProfiles\Pages\ListMemorialProfiles;
use App\Filament\Admin\Resources\MemorialProfiles\Pages\ViewMemorialProfile;
use App\Models\User;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * 2 Sep 2026 UAT finding: `MemorialProfileResource` had no `infolist()`
 * anywhere — not on the Resource, not on `ViewMemorialProfile` itself — so
 * the view page rendered its four relation-manager tabs above a completely
 * empty body, reproduced live against a real seeded profile. Never covered
 * by a test before this file.
 */
final class MemorialProfileResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_list_page_renders(): void
    {
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ListMemorialProfiles::class)->assertOk();
    }

    public function test_the_view_page_renders_the_profiles_own_fields(): void
    {
        $this->actingUserWithRole(ActorRole::ADMIN);
        $profile = $this->makeProfile();

        Livewire::test(ViewMemorialProfile::class, ['record' => $profile->getKey()])
            ->assertOk()
            ->assertSee('Publik')
            ->assertSee('Draft');
    }

    private function actingUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, $role);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        return $user;
    }

    private function forgetResolvedActorContext(): void
    {
        $this->app->forgetInstance(ActorContext::class);
        $this->app->forgetInstance(ActorContextResolver::class);
    }

    private function makeProfile(): MemorialProfile
    {
        $grave = GraveRecord::factory()->create();

        return app(CreateMemorialProfile::class)(
            $grave,
            'test-actor',
            'admin',
            'public',
            AuditSource::Console,
        );
    }
}
