<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `2026_09_17_100000_mark_fabricated_cemetery_names_as_examples`.
 *
 * `RefreshDatabase` runs the real migrations, which both seed the fabricated
 * cemeteries and then mark them — so the first test asserts the state the
 * migration chain actually produces, not a fixture built for it.
 *
 * The remaining tests insert their rows BY HAND. That is deliberate: the
 * migration's whole purpose is to catch rows an earlier seeder wrote, and a
 * test that built its fixture from today's generator could only ever exercise
 * today's generator (DB-13).
 */
final class MarkFabricatedCemeteryNamesTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = '(pemakaman contoh)';

    /**
     * Typed `object`, not `Migration`, matching
     * `AddOrderRefusedAfterPaymentNotificationTemplateTest::migration()`:
     * `up()`/`down()` live on the anonymous subclass the file returns, not on
     * the base `Migration`, so the narrower type is the one PHPStan can check.
     */
    private function migration(): object
    {
        return require base_path($this->migrationPath());
    }

    private function migrationPath(): string
    {
        return 'database/migrations/2026_09_17_100000_mark_fabricated_cemetery_names_as_examples.php';
    }

    /**
     * @return string the new row's id
     */
    private function insertCemetery(string $name, string $address): string
    {
        $id = (string) Str::uuid();

        DB::table('cemeteries')->insert([
            'id' => $id,
            'type' => 'tpu',
            'publication_status' => 'published',
            'name' => $name,
            'slug' => Str::slug($name).'-'.substr($id, 0, 8),
            'city' => 'Jakarta',
            'address' => $address,
            'facilities' => json_encode([]),
            'price_currency' => 'IDR',
            'operator_name' => 'Operator',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_every_seeded_cemetery_carries_the_marker_after_the_migrations_run(): void
    {
        $fabricated = DB::table('cemeteries')->where('address', 'like', 'Jl. Contoh%')->get();

        $this->assertGreaterThan(0, $fabricated->count(), 'Precondition: the seeder wrote fabricated cemeteries.');

        foreach ($fabricated as $cemetery) {
            $this->assertStringEndsWith(
                self::MARKER,
                $cemetery->name,
                "Fabricated cemetery [{$cemetery->slug}] renders its name without the marker.",
            );
        }
    }

    public function test_a_cemetery_with_a_real_address_is_never_touched(): void
    {
        $id = $this->insertCemetery('TPU Menteng Pulo', 'Jl. Casablanca Raya No. 4, Jakarta Selatan');

        $this->migration()->up();

        $this->assertSame('TPU Menteng Pulo', DB::table('cemeteries')->where('id', $id)->value('name'));
    }

    public function test_a_pre_generator_row_gets_the_marker_even_though_its_slug_is_unknown_today(): void
    {
        // The exact shape beta still holds: seeded 26 Jul 2026, so its slug is
        // absent from CemeteryExampleData::slugs() and only the address says
        // what it is.
        $id = $this->insertCemetery('TPU Jakarta Menteng', 'Jl. Contoh Melati No. 1, Menteng, Jakarta Pusat');

        $this->migration()->up();

        $this->assertSame(
            'TPU Jakarta Menteng '.self::MARKER,
            DB::table('cemeteries')->where('id', $id)->value('name'),
        );
    }

    public function test_running_it_again_does_not_append_the_marker_twice(): void
    {
        $id = $this->insertCemetery('TPU Jakarta Menteng', 'Jl. Contoh Melati No. 1, Menteng, Jakarta Pusat');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(
            'TPU Jakarta Menteng '.self::MARKER,
            DB::table('cemeteries')->where('id', $id)->value('name'),
        );
    }

    public function test_down_restores_the_original_name(): void
    {
        $id = $this->insertCemetery('TPU Jakarta Menteng', 'Jl. Contoh Melati No. 1, Menteng, Jakarta Pusat');

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame('TPU Jakarta Menteng', DB::table('cemeteries')->where('id', $id)->value('name'));
    }
}
