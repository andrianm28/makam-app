<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Concerns;

use Illuminate\Http\Response;

/**
 * Shared CSV line-building and streaming for the ADM-090 report pages
 * (`OrdersReport`, `ReceiptsReport`, `OutgoingPaymentsReport`,
 * `VendorPerformanceReport`, `RenewalPeriodReport`). Extracted once five
 * pages needed byte-identical quoting/escaping rather than five copies of
 * `Actions\BulkFinancialExport::toCsvLine()`'s logic drifting independently.
 *
 * Same RFC 4180 quoting and formula-injection neutralisation as
 * `BulkFinancialExport::toCsvLine()` — a leading `=`, `+`, `-` or `@` in a
 * text field gets a leading apostrophe, except when the field is itself
 * numeric (a negative amount is a number, not a formula).
 */
trait ExportsReportCsv
{
    /**
     * @param  list<string>  $fields
     */
    private function csvLine(array $fields): string
    {
        return implode(',', array_map(
            static function (string $field): string {
                if (! is_numeric($field) && preg_match('/\A[=+\-@]/', $field) === 1) {
                    $field = "'".$field;
                }

                return '"'.str_replace('"', '""', $field).'"';
            },
            $fields,
        ));
    }

    /**
     * @param  list<string>  $lines  Complete CSV lines, header first — each
     *                               one already built via `csvLine()`.
     */
    private function streamCsv(array $lines, string $filename): Response
    {
        $contents = implode("\n", $lines)."\n";

        return response()->streamDownload(
            static function () use ($contents): void {
                echo $contents;
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
