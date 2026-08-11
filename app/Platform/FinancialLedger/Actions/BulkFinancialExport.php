<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\FinancialLedger\BulkFinancialExportResult;
use App\Platform\FinancialLedger\Exceptions\BulkFinancialExportReauthenticationRequiredException;
use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use App\Platform\FinancialLedger\LedgerReport;
use App\Platform\FinancialLedger\LedgerReportKind;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * AC13: one bulk financial export — recent re-authentication required, then
 * the export, then the audit. Domain logic stays in here and in
 * `LedgerReport`; the HTTP controller and the Filament page only mount this.
 *
 * ---------------------------------------------------------------------------
 * Two gates, deliberately, at two different layers
 * ---------------------------------------------------------------------------
 * The HTTP entry point is protected by `RequireRecentAuthentication` (the
 * `mfa_disable` precedent: middleware on the route, redirecting a stale
 * actor to `MfaChallenge`, which is what actually re-proves identity). This
 * Action independently enforces the SAME policy from inside the domain, by
 * checking `reauthentication_events` for a satisfied event scoped to the
 * reason `bulk_financial_export` — exactly the discipline
 * `ManualPayout::assertApproverReauthenticatedRecently` establishes. The
 * middleware gates the route; this method gates every caller, including a
 * future non-HTTP one. A stale attempt is refused through
 * `ReauthenticationService::challenge()`, so the refusal itself lands in
 * `reauthentication_events` and the audit log, never only in an exception.
 *
 * The reason string is a deliberate free string, not an enum: the module
 * that owns re-authentication reasons documents them as free strings (see
 * `RequireRecentAuthentication`'s and `ReauthenticationService::challenge()`'s
 * doc blocks), and `routes/web.php`'s middleware attachment names it
 * independently — this constant and that route argument are the two places
 * it is written, and they must match.
 *
 * ---------------------------------------------------------------------------
 * `BULK_FINANCIAL_EXPORT` audit with a null reason
 * ---------------------------------------------------------------------------
 * Every export records an audit row. `BULK_FINANCIAL_EXPORT` is NOT on
 * `SensitiveActions::ACTIONS` (the plan's Global Constraints fix this
 * lane's growth of that list at exactly `RECONCILIATION_EXCEPTION_RESOLVED`,
 * landed in Task 5), so `Audit::record()` accepts a null reason here — the
 * export is a read, and the report kind/period label in `metadata.note` is
 * all the audit row needs. Metadata uses only `note`, a key already on
 * `MetadataAllowlist` (no amounts, no identity, no statement reference).
 */
final class BulkFinancialExport
{
    public const string AUDIT_ACTION = 'BULK_FINANCIAL_EXPORT';

    /**
     * Must match the `$reason` passed to `RequireRecentAuthentication` for
     * the export route in `routes/web.php`.
     */
    public const string REAUTHENTICATION_REASON = 'bulk_financial_export';

    private const string MIME_TYPE_CSV = 'text/csv; charset=UTF-8';

    private const string CURRENCY = 'IDR';

    public function __construct(
        private readonly ReauthenticationService $reauthentication = new ReauthenticationService,
        private readonly LedgerReport $report = new LedgerReport,
    ) {}

    /**
     * Produce one bulk financial export.
     *
     * @param  string  $kind  One of `LedgerReportKind::KNOWN_KINDS`.
     * @param  string  $period  `YYYY-MM`, the report period.
     * @param  int|string  $actorRef  The exporting actor (from the
     *                                authenticated session's own identifier — never caller-supplied
     *                                for authorization purposes).
     * @param  string  $actorRole  Required for the audit row, mirroring
     *                             `Audit::record()`'s own always-required rule.
     *
     * @throws BulkFinancialExportReauthenticationRequiredException when the
     *                                                              actor has no satisfied re-authentication for
     *                                                              `bulk_financial_export` within the configured freshness
     *                                                              window (the refusal is recorded via
     *                                                              `ReauthenticationService::challenge()` first).
     * @throws InvalidArgumentException when `$kind` is not a known report kind.
     * @throws InvalidLedgerReportException on a malformed `$period`.
     */
    public function export(
        string $kind,
        string $period,
        ?string $entityRef = null,
        int|string|null $actorRef = null,
        string $actorRole = 'authenticated_actor',
        string $ip = '0.0.0.0',
        ?string $correlationId = null,
    ): BulkFinancialExportResult {
        LedgerReportKind::assertKnown($kind);

        // Validate the period BEFORE the re-authentication gate so a
        // malformed request is refused without recording a challenge it
        // never should have needed — same ordering discipline as
        // `ManualPayout`, which validates inputs before the freshness check.
        $this->report->assertPeriod($period);

        $this->assertReauthenticatedRecently($actorRef, $actorRole, $ip);

        $result = $this->report->summary($period, $entityRef);

        $correlationId ??= app(CorrelationContext::class)->current()?->value;

        $debitTotal = array_sum(array_column($result->rows, 'debit_total'));
        $creditTotal = array_sum(array_column($result->rows, 'credit_total'));

        // The audit label: report kind, period, and scope — no amount, no
        // identity, no statement reference, no free text.
        $reference = "{$kind}:{$period}:".($entityRef ?? 'all');

        Audit::record(
            action: self::AUDIT_ACTION,
            subject: new AuditSubject('financial_ledger_report', $reference),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: AuditSource::Panel,
            reason: null,
            correlationId: $correlationId,
            metadata: ['note' => $reference],
        );

        return new BulkFinancialExportResult(
            filename: "financial-ledger-{$kind}-{$period}.csv",
            mimeType: self::MIME_TYPE_CSV,
            contents: $this->toCsv($result->rows, $debitTotal, $creditTotal),
            debitTotal: $debitTotal,
            creditTotal: $creditTotal,
            report: $result,
        );
    }

    /**
     * Deterministic CSV: fixed header, account rows in the report's own
     * explicit `account_code` order, a single `TOTAL` row, integer minor-unit
     * amounts only, LF line endings, one trailing newline. Same ledger +
     * period + kind in → same bytes out.
     *
     * @param  list<array{account_code: string, debit_total: int, credit_total: int, net: int}>  $rows
     */
    private function toCsv(array $rows, int $debitTotal, int $creditTotal): string
    {
        $lines = ['account_code,debit_total,credit_total,net,currency'];

        foreach ($rows as $row) {
            $lines[] = implode(',', [
                $row['account_code'],
                (string) $row['debit_total'],
                (string) $row['credit_total'],
                (string) $row['net'],
                self::CURRENCY,
            ]);
        }

        $lines[] = implode(',', ['TOTAL', (string) $debitTotal, (string) $creditTotal, '0', self::CURRENCY]);

        return implode("\n", $lines)."\n";
    }

    private function assertReauthenticatedRecently(
        int|string|null $actorRef,
        string $actorRole,
        string $ip,
    ): void {
        $freshnessSeconds = (int) config('reauthentication.freshness_seconds');

        $fresh = ReauthenticationEvent::query()
            ->where('actor_ref', $actorRef)
            ->where('outcome', ReauthenticationOutcome::SATISFIED)
            ->where('reason', self::REAUTHENTICATION_REASON)
            ->where('occurred_at', '>=', CarbonImmutable::now()->subSeconds($freshnessSeconds))
            ->exists();

        if ($fresh) {
            return;
        }

        // Record the refusal through the service that owns re-authentication,
        // so a stale export attempt is visible in `reauthentication_events`
        // and in the audit log, not only in whatever caught the exception.
        $this->reauthentication->challenge(
            actorRef: $actorRef,
            actorRole: $actorRole,
            reason: self::REAUTHENTICATION_REASON,
            source: AuditSource::Panel,
            ip: $ip,
        );

        throw BulkFinancialExportReauthenticationRequiredException::forActor($actorRef ?? 'guest', $freshnessSeconds);
    }
}
