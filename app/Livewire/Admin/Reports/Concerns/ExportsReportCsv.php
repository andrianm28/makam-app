<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared CSV line-building and streaming for the ADM-090 report tabs
 * (`OrdersReportPanel`, `ReceiptsReportPanel`, `OutgoingPaymentsReportPanel`,
 * `VendorPerformanceReportPanel`, `RenewalPeriodReportPanel`) hosted inside
 * `App\Filament\Admin\Pages\Reports`. Moved verbatim from the former
 * `App\Filament\Admin\Pages\Concerns\ExportsReportCsv` (the same six-page
 * consolidation this batch performs moved the five report Filament pages
 * that used it into plain nested Livewire components) — the RFC 4180
 * quoting/escaping logic itself is unchanged.
 *
 * Same RFC 4180 quoting and formula-injection neutralisation as
 * `BulkFinancialExport::toCsvLine()`: a leading `=`, `+`, `-` or `@` in a
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
     *
     * Return type is `StreamedResponse`, not `Illuminate\Http\Response`
     * (2 Sep 2026 UAT fix) — `response()->streamDownload()` returns the
     * former, a sibling of the latter under `Symfony\Component\
     * HttpFoundation\Response`, never the latter itself. The old hint made
     * every CSV export on all five report tabs throw a `TypeError` on
     * every real attempt (reproduced live via the Orders Report export
     * button); every `exportCsv()` caller's own return type needed the
     * same fix.
     */
    private function streamCsv(array $lines, string $filename): StreamedResponse
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
