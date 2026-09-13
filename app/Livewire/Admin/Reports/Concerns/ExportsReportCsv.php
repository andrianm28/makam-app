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

    /**
     * PERF-14 — the `cursor()`-backed counterpart of `streamCsv()`: each
     * already-built CSV line is echoed as it is produced by `$lines`
     * (typically a generator wrapping a `LazyCollection::cursor()`),
     * instead of first collecting every line into one array and
     * `implode()`-ing it. This is what actually keeps a large export from
     * holding the full result set in PHP memory — `streamDownload()`
     * alone does not do that if the callback still builds `$lines` as a
     * complete array before echoing it.
     *
     * @param  iterable<string>  $lines  Complete CSV lines, header first —
     *                                   each one already built via
     *                                   `csvLine()`.
     */
    private function streamCsvRows(iterable $lines, string $filename): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($lines): void {
                foreach ($lines as $line) {
                    echo $line."\n";
                }
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
