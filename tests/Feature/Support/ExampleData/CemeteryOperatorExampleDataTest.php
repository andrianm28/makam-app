<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Support\ExampleData\CemeteryOperatorExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CemeteryOperatorExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_a_scoped_operator_with_a_working_login(): void
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Contoh Operator',
            'slug' => 'tpu-contoh-operator-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $batchId = (string) Str::uuid();

        $user = CemeteryOperatorExampleData::seed($batchId, $cemetery->id);

        $this->assertSame($batchId, $user->fresh()->demo_batch_id);
        $this->assertTrue(Hash::check('DemoContoh2026!', $user->fresh()->password));
        $this->assertDatabaseHas('actor_role_assignments', [
            'actor_identifier' => (string) $user->id,
            'role' => ActorRole::CEMETERY_OPERATOR,
            'demo_batch_id' => $batchId,
        ]);
        $this->assertDatabaseHas('scope_assignments', [
            'actor_identifier' => (string) $user->id,
            'entity_id' => $cemetery->id,
            'demo_batch_id' => $batchId,
        ]);
    }
}
