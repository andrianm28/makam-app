<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reconciliations\Support;

use App\Platform\FinancialLedger\Money;
use InvalidArgumentException;
use OverflowException;

/**
 * Maps an admin-uploaded settlement CSV to the `array<string, int>` shape
 * `App\Platform\FinancialLedger\ProviderStatement::$lines` expects — opaque
 * line reference => integer minor units.
 *
 * ---------------------------------------------------------------------------
 * This is the "caller" `ProviderStatement`'s own doc block already names
 * ---------------------------------------------------------------------------
 * `ProviderStatement`'s class doc block is explicit that projecting a real
 * provider payload down to references and integer minor units is the
 * CALLER's job, not something the value object or the reconciliation engine
 * does. This class is that caller-side projection for exactly one shape of
 * input — a two-column CSV — and lives beside the Filament admin resource
 * that is its only caller, not inside `app/Platform/FinancialLedger/`: it
 * does not touch the comparison engine, and `Platform/**` foundations are
 * consumed here, never redefined.
 *
 * ---------------------------------------------------------------------------
 * The CSV format this parser accepts, and why it is this narrow
 * ---------------------------------------------------------------------------
 * Two columns, exact lower-case header names, one settled line per row:
 *
 *   line_reference,amount
 *   trx-00001,150000.00
 *   trx-00002,275500
 *
 * `line_reference` is SumoPod's (or whichever provider's) own opaque
 * transaction/settlement-line identifier — never a bank detail, an account
 * number, or a customer identity (the same "opaque references only"
 * constraint `ProviderStatement` itself documents and enforces).
 * `amount` is a plain decimal string in MAJOR units (rupiah, e.g. "150000.00"
 * or "150000") — no thousands separator, no currency symbol — converted here
 * to integer minor units via `Money::fromDecimal()`, the same conversion the
 * rest of this module already uses at its own decimal-input boundary. The
 * statement's own `reference`/`period`/`entityRef` are NOT columns in this
 * file; they are entered once per upload through the admin form, because a
 * settlement export is naturally already scoped to one statement, one
 * period, and one badan usaha.
 *
 * THIS MAPPING IS UNVERIFIED AGAINST A REAL SUMOPOD EXPORT. No live SumoPod
 * statement sample was available to design against — confirmed by direct
 * investigation that SumoPod exposes settlement data only through its own
 * dashboard, with no API to pull a real sample from programmatically. This
 * format is deliberately the simplest shape that is obviously mappable to
 * what a settlement export typically contains (a transaction reference and
 * an amount); the exact column names, decimal format (e.g. a
 * "150.000,00"-style Indonesian thousands separator instead of a plain
 * decimal), or any additional required column may need to change once a
 * human has a real SumoPod CSV export to test against. That review is
 * flagged, not performed here.
 *
 * ---------------------------------------------------------------------------
 * Division of validation labour with `ProviderStatement`
 * ---------------------------------------------------------------------------
 * This parser rejects structural problems with the FILE — a missing/renamed
 * header, a row with the wrong column count, a duplicate `line_reference`
 * (silently last-write-wins if left to a plain PHP array, which is a real
 * finding lost rather than an error), and an `amount` cell
 * `Money::fromDecimal()` cannot parse. It deliberately does NOT re-implement
 * "reference is non-blank" or "amount is non-negative" — `ProviderStatement`'s
 * own constructor is the single authority for those, and the caller
 * (`Actions\UploadProviderStatementAction`) catches both this class's
 * exception and `InvalidReconciliationException` the same way, so neither
 * rule can drift out of step with the other.
 */
final class ProviderStatementCsvParser
{
    public const string HEADER_LINE_REFERENCE = 'line_reference';

    public const string HEADER_AMOUNT = 'amount';

    /**
     * A settlement statement is a periodic batch export, not an
     * unboundedly-large data dump — this cap is a sanity/resource-exhaustion
     * guard, not a business limit. Flagged for review alongside the rest of
     * this file's format, since no real export size is known yet.
     */
    private const int MAX_DATA_ROWS = 10_000;

    /**
     * @return array<string, int> opaque line reference => integer minor units
     *
     * @throws ProviderStatementCsvException on any structural problem with
     *                                       the file itself — see class doc block.
     */
    public function parse(string $csvContents): array
    {
        $rows = $this->splitRows($csvContents);

        if ($rows === []) {
            throw ProviderStatementCsvException::forEmptyFile();
        }

        $header = array_map($this->normalizeCell(...), array_shift($rows));

        if ($header !== [self::HEADER_LINE_REFERENCE, self::HEADER_AMOUNT]) {
            throw ProviderStatementCsvException::forUnexpectedHeader($header);
        }

        if (count($rows) > self::MAX_DATA_ROWS) {
            throw ProviderStatementCsvException::forTooManyRows(count($rows), self::MAX_DATA_ROWS);
        }

        /** @var array<string, int> $lines */
        $lines = [];

        foreach ($rows as $index => $row) {
            // 1-based, and the header already consumed row 1 — so the first
            // data row is row 2, matching what a spreadsheet application
            // shows an admin looking at the same file.
            $lineNumber = $index + 2;

            if (count($row) !== 2) {
                throw ProviderStatementCsvException::forMalformedRow($lineNumber);
            }

            $reference = $this->normalizeCell($row[0]);
            $rawAmount = $this->normalizeCell($row[1]);

            if ($reference === '') {
                throw ProviderStatementCsvException::forBlankReference($lineNumber);
            }

            if (array_key_exists($reference, $lines)) {
                throw ProviderStatementCsvException::forDuplicateReference($lineNumber, $reference);
            }

            try {
                $amountMinor = Money::fromDecimal($rawAmount);
            } catch (InvalidArgumentException|OverflowException) {
                throw ProviderStatementCsvException::forInvalidAmount($lineNumber, $rawAmount);
            }

            $lines[$reference] = $amountMinor;
        }

        if ($lines === []) {
            throw ProviderStatementCsvException::forNoDataRows();
        }

        return $lines;
    }

    /**
     * @return list<list<string|null>>
     */
    private function splitRows(string $csvContents): array
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw ProviderStatementCsvException::forEmptyFile();
        }

        // A leading UTF-8 BOM is common in spreadsheet-exported CSVs and
        // would otherwise be read as part of the first header cell,
        // producing a spurious "unexpected header" refusal.
        fwrite($stream, $this->stripBom($csvContents));
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream)) !== false) {
            if ($row === [null]) {
                continue; // a wholly blank physical line
            }

            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }

    private function stripBom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }

    private function normalizeCell(?string $value): string
    {
        return trim($value ?? '');
    }
}
