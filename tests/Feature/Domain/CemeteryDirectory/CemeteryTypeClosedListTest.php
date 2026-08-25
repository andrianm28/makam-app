<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `App\Domain\CemeteryDirectory\CemeteryType` is a CLOSED list — exactly
 * `TPU`/`TPS`. Backs `docs/contracts/openapi.yaml`'s `CemeterySummary.type`
 * enum and requirements.md AC3 (type is a required display field).
 */
final class CemeteryTypeClosedListTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_two_known_types(): void
    {
        $this->assertSame(['TPU', 'TPS'], CemeteryType::KNOWN_TYPES);
    }

    public function test_is_known_accepts_only_the_two_types(): void
    {
        $this->assertTrue(CemeteryType::isKnown('TPU'));
        $this->assertTrue(CemeteryType::isKnown('TPS'));
        $this->assertFalse(CemeteryType::isKnown('TPZ'));
        $this->assertFalse(CemeteryType::isKnown('tpu'));
        $this->assertFalse(CemeteryType::isKnown(''));
    }

    public function test_assert_known_throws_for_an_unknown_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CemeteryType::assertKnown('TPZ');
    }

    public function test_the_model_rejects_an_unknown_type_on_save(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cemetery::create([
            'type' => 'TPZ',
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'Cemetery With Bad Type',
            'slug' => 'cemetery-with-bad-type',
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Uji Coba No. 1',
        ]);
    }

    public function test_the_model_accepts_every_known_type(): void
    {
        foreach (CemeteryType::KNOWN_TYPES as $index => $type) {
            $cemetery = Cemetery::create([
                'type' => $type,
                'publication_status' => CemeteryPublicationStatus::PUBLISHED,
                'name' => "Cemetery Type Check {$index}",
                'slug' => "cemetery-type-check-{$index}",
                'city' => LaunchCityCode::JAKARTA,
                'address' => 'Jl. Uji Coba No. 1',
            ]);

            $this->assertSame($type, $cemetery->fresh()->type);
        }
    }
}
