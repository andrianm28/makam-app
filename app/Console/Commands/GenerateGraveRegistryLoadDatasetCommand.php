<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\GraveRegistry\GraveNameNormalizer;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `php artisan bench:generate-grave-dataset` — builds the synthetic dataset
 * `docs/operations/performance-and-capacity.md` §5 specifies for load/AC4
 * benchmarking: "100,000 grave records... at least 100 cemeteries across
 * five launch areas." Deliberately separate from
 * `App\Support\ExampleData\CemeteryExampleData`, whose ~15-row fixture set
 * is sized for functional tests, not bulk-load benchmarking.
 *
 * Bulk `DB::table()->insert()` in chunks, not one Eloquent model per row —
 * `GraveRecord::booted()`'s `saving` hook (name normalization, access-mode
 * validation) never fires on a bulk insert, so this command computes
 * `deceased_name_normalized` itself via the same
 * `GraveNameNormalizer::normalize()` the model uses, and writes
 * `access_mode` explicitly on every row (the column default only applies
 * to a raw insert that OMITS the column, and this command never does).
 *
 * Re-running replaces rather than accumulates: every cemetery this command
 * creates carries the fixed, greppable name prefix "Contoh TPU Beban ",
 * making its own rows (and only its own rows) identifiable for deletion
 * before regenerating — this command never touches a cemetery it did not
 * itself create.
 */
final class GenerateGraveRegistryLoadDatasetCommand extends Command
{
    private const string CEMETERY_NAME_PREFIX = 'Contoh TPU Beban ';

    /**
     * Deliberately fake, clearly-marked Indonesian given/family name
     * components — combined and repeated with deterministic index-based
     * variation (never claimed as real identity data, matching this
     * repo's "THIS IS DUMMY DATA" convention).
     */
    private const array GIVEN_NAMES = [
        'Siti', 'Budi', 'Ahmad', 'Dewi', 'Agus', 'Rina', 'Hendra', 'Sri',
        'Wahyu', 'Yuni', 'Bambang', 'Ani', 'Joko', 'Fitri', 'Slamet', 'Lestari',
    ];

    private const array FAMILY_NAMES = [
        'Wijaya', 'Santoso', 'Kusuma', 'Pratama', 'Saputra', 'Hidayat',
        'Gunawan', 'Setiawan', 'Rahman', 'Suryanto',
    ];

    protected $signature = 'bench:generate-grave-dataset
        {--cemeteries=100 : Number of synthetic cemeteries to generate}
        {--records=100000 : Total grave records to distribute across them}
        {--chunk=1000 : Rows per bulk-insert statement}';

    protected $description = 'Generate a synthetic grave-registry dataset for AC4/load benchmarking (docs/operations/performance-and-capacity.md §5).';

    public function handle(): int
    {
        $cemeteryCount = (int) $this->option('cemeteries');
        $recordCount = (int) $this->option('records');
        $chunkSize = (int) $this->option('chunk');

        $this->info('Removing any previously generated benchmark cemeteries...');
        $previousIds = DB::table('cemeteries')
            ->where('name', 'like', self::CEMETERY_NAME_PREFIX.'%')
            ->pluck('id');
        DB::table('grave_records')->whereIn('cemetery_id', $previousIds)->delete();
        DB::table('cemeteries')->whereIn('id', $previousIds)->delete();

        $this->info("Generating {$cemeteryCount} cemeteries...");
        $cemeteryIds = $this->generateCemeteries($cemeteryCount);

        $this->info("Generating {$recordCount} grave records across them...");
        $this->generateGraveRecords($cemeteryIds, $recordCount, $chunkSize);

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function generateCemeteries(int $count): array
    {
        $cityCodes = LaunchCityCode::KNOWN_CODES;
        $now = now();
        $ids = [];
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::uuid();
            $ids[] = $id;
            $city = $cityCodes[$i % count($cityCodes)];

            $rows[] = [
                'id' => $id,
                'type' => $i % 3 === 0 ? CemeteryType::TPS : CemeteryType::TPU,
                'publication_status' => CemeteryPublicationStatus::PUBLISHED,
                'name' => self::CEMETERY_NAME_PREFIX.($i + 1),
                'slug' => Str::slug(self::CEMETERY_NAME_PREFIX.($i + 1)).'-'.Str::lower(Str::random(6)),
                'city' => $city,
                'address' => 'Jl. Contoh Pemakaman No. '.($i + 1).', '.$city,
                'price_currency' => 'IDR',
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        collect($rows)->chunk(500)->each(
            fn ($chunk) => DB::table('cemeteries')->insert($chunk->all())
        );

        return $ids;
    }

    /**
     * @param  list<string>  $cemeteryIds
     */
    private function generateGraveRecords(array $cemeteryIds, int $totalRecords, int $chunkSize): void
    {
        $now = now();
        $buffer = [];
        $written = 0;
        $bar = $this->output->createProgressBar($totalRecords);

        for ($i = 0; $i < $totalRecords; $i++) {
            $cemeteryId = $cemeteryIds[$i % count($cemeteryIds)];
            $given = self::GIVEN_NAMES[$i % count(self::GIVEN_NAMES)];
            $family = self::FAMILY_NAMES[($i * 7) % count(self::FAMILY_NAMES)];

            // Deterministic "spelling variation" for roughly one in five
            // rows — performance-and-capacity.md §5 asks for these
            // explicitly, and a fuzzy-search benchmark that never
            // exercises the similarity() branch at all would be
            // measuring the wrong query shape.
            $name = $i % 5 === 0
                ? $given.' '.$family.'h' // a trailing-letter typo variant
                : $given.' '.$family;

            $normalized = GraveNameNormalizer::normalize($name);

            $year = 2010 + ($i % 15);
            $month = ($i % 12) + 1;
            $day = ($i % 28) + 1;

            $buffer[] = [
                'id' => (string) Str::uuid(),
                'cemetery_id' => $cemeteryId,
                'deceased_name' => $name,
                'deceased_name_normalized' => $normalized,
                'block' => sprintf('BLOK-%02d', ($i % 20) + 1),
                'death_date' => sprintf('%d-%02d-%02d', $year, $month, $day),
                'due_date' => sprintf('%d-%02d-%02d', $year + 10, $month, $day),
                'access_mode' => GraveRecordAccessMode::OPEN,
                'source' => 'bench-generator',
                'source_updated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) >= $chunkSize) {
                DB::table('grave_records')->insert($buffer);
                $written += count($buffer);
                $bar->advance(count($buffer));
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('grave_records')->insert($buffer);
            $written += count($buffer);
            $bar->advance(count($buffer));
        }

        $bar->finish();
        $this->newLine();
        $this->info("Wrote {$written} grave records.");
    }
}
