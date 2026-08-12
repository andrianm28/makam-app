<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RenewalQuote>
 */
final class RenewalQuoteFactory extends Factory
{
    protected $model = RenewalQuote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cemetery = Cemetery::query()->first();
        $grave = $cemetery
            ? GraveRecord::factory()->create(['cemetery_id' => $cemetery->id])
            : GraveRecord::factory()->create();

        return [
            'renewal_id' => fn () => Renewal::factory()->create(['grave_record_id' => $grave->id])->id,
            'amount_minor' => 150_000_00,
            'currency' => 'IDR',
            'tariff_source' => 'Estimasi internal (data contoh)',
            'tariff_effective_at' => now(),
            'tariff_source_updated_at' => now(),
            'late_fine_minor' => null,
            'late_fine_basis' => null,
            'accepted_at' => null,
            'expires_at' => now()->addDays(30),
        ];
    }

    public function accepted(): self
    {
        return $this->state(fn (array $attrs): array => [
            'accepted_at' => now(),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (array $attrs): array => [
            'accepted_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);
    }
}
