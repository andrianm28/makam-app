<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Admin\Reconciliations;

use App\Filament\Admin\Resources\Reconciliations\Support\ProviderStatementCsvException;
use App\Filament\Admin\Resources\Reconciliations\Support\ProviderStatementCsvParser;
use Tests\TestCase;

/**
 * `ProviderStatementCsvParser` in isolation, no HTTP/Livewire/database
 * involved — every structural-rejection path the class doc block promises,
 * plus the happy path's exact integer-minor-unit conversion.
 */
final class ProviderStatementCsvParserTest extends TestCase
{
    public function test_a_well_formed_csv_parses_to_opaque_reference_minor_unit_pairs(): void
    {
        $lines = $this->parser()->parse(
            "line_reference,amount\ntrx-00001,150000.00\ntrx-00002,275500\n"
        );

        $this->assertSame([
            'trx-00001' => 15_000_000,
            'trx-00002' => 27_550_000,
        ], $lines);
    }

    public function test_a_leading_utf8_bom_does_not_corrupt_the_header(): void
    {
        $lines = $this->parser()->parse("\xEF\xBB\xBFline_reference,amount\ntrx-1,100\n");

        $this->assertSame(['trx-1' => 10_000], $lines);
    }

    public function test_a_trailing_blank_line_is_ignored(): void
    {
        $lines = $this->parser()->parse("line_reference,amount\ntrx-1,100\n\n");

        $this->assertSame(['trx-1' => 10_000], $lines);
    }

    public function test_an_empty_file_is_refused(): void
    {
        $this->expectException(ProviderStatementCsvException::class);
        $this->expectExceptionMessage('Berkas CSV kosong');

        $this->parser()->parse('');
    }

    public function test_an_unexpected_header_is_refused(): void
    {
        $this->expectException(ProviderStatementCsvException::class);
        $this->expectExceptionMessage('Header CSV tidak sesuai');

        $this->parser()->parse("reference,amount\ntrx-1,100\n");
    }

    public function test_a_header_only_file_is_refused_as_having_no_data_rows(): void
    {
        $this->expectException(ProviderStatementCsvException::class);
        $this->expectExceptionMessage('tidak memiliki baris data');

        $this->parser()->parse("line_reference,amount\n");
    }

    public function test_a_row_with_the_wrong_column_count_is_refused_with_its_line_number(): void
    {
        $this->expectException(ProviderStatementCsvException::class);
        $this->expectExceptionMessage('Baris 3');

        $this->parser()->parse("line_reference,amount\ntrx-1,100\ntrx-2,100,extra\n");
    }

    public function test_a_blank_reference_is_refused_with_its_line_number(): void
    {
        $this->expectException(ProviderStatementCsvException::class);
        $this->expectExceptionMessage('Baris 2');

        $this->parser()->parse("line_reference,amount\n,100\n");
    }

    public function test_a_duplicate_reference_is_refused_rather_than_silently_overwriting_the_first(): void
    {
        $this->expectException(ProviderStatementCsvException::class);
        $this->expectExceptionMessage('trx-1');

        $this->parser()->parse("line_reference,amount\ntrx-1,100\ntrx-1,200\n");
    }

    public function test_a_non_numeric_amount_is_refused_with_its_raw_value(): void
    {
        $this->expectException(ProviderStatementCsvException::class);
        $this->expectExceptionMessage('seratus ribu');

        $this->parser()->parse("line_reference,amount\ntrx-1,seratus ribu\n");
    }

    public function test_a_thousands_separated_amount_is_refused_not_silently_misread(): void
    {
        // Money::fromDecimal has no thousands-separator support — a value
        // like "150.000,00" (Indonesian-formatted) must be refused, never
        // silently parsed as "150" rupiah with a stray ",00" truncated away.
        $this->expectException(ProviderStatementCsvException::class);

        $this->parser()->parse("line_reference,amount\ntrx-1,\"150.000,00\"\n");
    }

    public function test_more_than_the_row_cap_is_refused(): void
    {
        $rows = ['line_reference,amount'];

        for ($i = 0; $i < 10_001; $i++) {
            $rows[] = "trx-{$i},100";
        }

        $this->expectException(ProviderStatementCsvException::class);
        $this->expectExceptionMessage('melebihi batas maksimum');

        $this->parser()->parse(implode("\n", $rows)."\n");
    }

    private function parser(): ProviderStatementCsvParser
    {
        return new ProviderStatementCsvParser;
    }
}
