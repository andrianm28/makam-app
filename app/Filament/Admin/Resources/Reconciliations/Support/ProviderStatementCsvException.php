<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reconciliations\Support;

use InvalidArgumentException;

/**
 * A provider-statement CSV upload was refused before any `ProviderStatement`
 * was even attempted — a structural problem with the FILE itself (missing
 * header, wrong column count, a duplicate line reference, an unparsable
 * amount) rather than a business-rule rejection `ProviderStatement`'s own
 * constructor already enforces (blank reference, negative amount). Those stay
 * `ProviderStatement`'s job so the rule is not duplicated in two places; see
 * `ProviderStatementCsvParser` class doc block.
 *
 * Every message is safe to show an admin directly in a Filament notification:
 * a 1-based row number (header counts as row 1, so the first data row is row
 * 2 — matching what a spreadsheet application shows) and the offending cell
 * content, never a stack trace.
 */
final class ProviderStatementCsvException extends InvalidArgumentException
{
    public static function forEmptyFile(): self
    {
        return new self('Berkas CSV kosong. Sertakan baris header dan minimal satu baris data.');
    }

    /**
     * @param  list<string>  $header
     */
    public static function forUnexpectedHeader(array $header): self
    {
        $rendered = implode(',', $header);

        return new self(
            "Header CSV tidak sesuai: [{$rendered}]. Header yang diharapkan: "
            .ProviderStatementCsvParser::HEADER_LINE_REFERENCE.','
            .ProviderStatementCsvParser::HEADER_AMOUNT.'.'
        );
    }

    public static function forTooManyRows(int $actual, int $max): self
    {
        return new self(
            "Berkas berisi {$actual} baris data, melebihi batas maksimum {$max} baris per unggahan."
        );
    }

    public static function forMalformedRow(int $lineNumber): self
    {
        return new self(
            "Baris {$lineNumber}: jumlah kolom tidak sesuai. Setiap baris data harus memiliki tepat "
            .'dua kolom: referensi baris dan jumlah.'
        );
    }

    public static function forBlankReference(int $lineNumber): self
    {
        return new self("Baris {$lineNumber}: kolom referensi baris (line_reference) kosong.");
    }

    public static function forDuplicateReference(int $lineNumber, string $reference): self
    {
        return new self(
            "Baris {$lineNumber}: referensi baris [{$reference}] sudah muncul sebelumnya pada berkas "
            .'ini. Setiap referensi baris harus unik dalam satu pernyataan.'
        );
    }

    public static function forInvalidAmount(int $lineNumber, string $rawAmount): self
    {
        return new self(
            "Baris {$lineNumber}: jumlah [{$rawAmount}] bukan angka desimal yang valid. Gunakan "
            .'format polos tanpa pemisah ribuan, contoh: 150000 atau 150000.00.'
        );
    }

    public static function forNoDataRows(): self
    {
        return new self('Berkas CSV tidak memiliki baris data setelah header.');
    }
}
