<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TaggedAsDemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_tag_sets_demo_batch_id_and_saves(): void
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $batchId = (string) Str::uuid();

        TaggedAsDemoData::tag($cemetery, $batchId);

        $this->assertSame($batchId, $cemetery->fresh()->demo_batch_id);
    }
}
