<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class GenerateGraveRegistryLoadDatasetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_the_requested_cemetery_and_record_counts(): void
    {
        $exitCode = Artisan::call('bench:generate-grave-dataset', [
            '--cemeteries' => 5,
            '--records' => 50,
            '--chunk' => 10,
        ]);

        $this->assertSame(0, $exitCode);
        $benchmarkCemeteries = Cemetery::query()->where('name', 'like', 'Contoh TPU Beban %')->count();
        $this->assertSame(5, $benchmarkCemeteries);
        $benchmarkCemeteryIds = Cemetery::query()->where('name', 'like', 'Contoh TPU Beban %')->pluck('id');
        $generatedCount = GraveRecord::query()->whereIn('cemetery_id', $benchmarkCemeteryIds)->count();
        $this->assertSame(50, $generatedCount);
    }

    public function test_generated_records_have_a_real_normalized_name_and_are_distributed_across_cemeteries(): void
    {
        Artisan::call('bench:generate-grave-dataset', [
            '--cemeteries' => 5,
            '--records' => 50,
            '--chunk' => 10,
        ]);

        $benchmarkCemeteryIds = Cemetery::query()->where('name', 'like', 'Contoh TPU Beban %')->pluck('id');
        $record = GraveRecord::query()->whereIn('cemetery_id', $benchmarkCemeteryIds)->first();
        $this->assertNotNull($record);
        $this->assertNotSame('', $record->deceased_name_normalized);
        $distinctCemeteries = GraveRecord::query()->whereIn('cemetery_id', $benchmarkCemeteryIds)->distinct('cemetery_id')->count('cemetery_id');
        $this->assertSame(5, $distinctCemeteries);
    }

    public function test_it_is_re_runnable_and_replaces_rather_than_accumulates(): void
    {
        Artisan::call('bench:generate-grave-dataset', ['--cemeteries' => 3, '--records' => 30, '--chunk' => 10]);
        Artisan::call('bench:generate-grave-dataset', ['--cemeteries' => 3, '--records' => 30, '--chunk' => 10]);

        $this->assertSame(3, Cemetery::query()->where('name', 'like', 'Contoh TPU Beban %')->count());
        $benchmarkCemeteryIds = Cemetery::query()->where('name', 'like', 'Contoh TPU Beban %')->pluck('id');
        $generatedCount = GraveRecord::query()->whereIn('cemetery_id', $benchmarkCemeteryIds)->count();
        $this->assertSame(30, $generatedCount);
    }
}
