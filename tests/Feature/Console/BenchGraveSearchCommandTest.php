<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\GraveRecordSource;
use App\Domain\GraveRegistry\Models\GraveRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BenchGraveSearchCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_real_percentiles_and_succeeds_when_fast_enough(): void
    {
        $cemetery = Cemetery::query()->create([
            'type' => 'TPU',
            'publication_status' => 'published',
            'name' => 'TPU Uji Benchmark',
            'slug' => 'tpu-uji-benchmark-'.Str::lower(Str::random(6)),
            'city' => 'JAKARTA',
            'address' => 'Jl. Uji No. 1',
            'published_at' => now(),
        ]);

        for ($i = 0; $i < 20; $i++) {
            GraveRecord::query()->create([
                'cemetery_id' => $cemetery->getKey(),
                'deceased_name' => 'Contoh Nama '.$i,
                'block' => 'BLOK-01',
                'death_date' => '2020-01-01',
                'access_mode' => GraveRecordAccessMode::OPEN,
                'source' => GraveRecordSource::CONTOH,
            ]);
        }

        $exitCode = Artisan::call('bench:grave-search', ['--iterations' => 10]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('p50', $output);
        $this->assertStringContainsString('p95', $output);
        $this->assertStringContainsString('p99', $output);
    }

    public function test_it_fails_the_command_when_p95_exceeds_the_ac4_target(): void
    {
        $cemetery = Cemetery::query()->create([
            'type' => 'TPU',
            'publication_status' => 'published',
            'name' => 'TPU Uji Benchmark Lambat',
            'slug' => 'tpu-uji-benchmark-lambat-'.Str::lower(Str::random(6)),
            'city' => 'JAKARTA',
            'address' => 'Jl. Uji No. 2',
            'published_at' => now(),
        ]);

        GraveRecord::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'deceased_name' => 'Contoh Nama',
            'block' => 'BLOK-01',
            'death_date' => '2020-01-01',
            'access_mode' => GraveRecordAccessMode::OPEN,
            'source' => GraveRecordSource::CONTOH,
        ]);

        $exitCode = Artisan::call('bench:grave-search', ['--iterations' => 5, '--fail-threshold-ms' => -1]);

        $this->assertSame(1, $exitCode);
    }
}
