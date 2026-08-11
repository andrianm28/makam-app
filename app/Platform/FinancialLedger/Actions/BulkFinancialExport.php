<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\FinancialLedger\BulkFinancialExportResult;
use App\Platform\FinancialLedger\Contracts\LedgerReadAuthorizer;
use App\Platform\FinancialLedger\Exceptions\BulkFinancialExportReauthenticationRequiredException;
use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\FinancialLedger\FinanceLedgerReadAuthorizer;
use App\Platform\FinancialLedger\LedgerReport;
use App\Platform\FinancialLedger\LedgerReportKind;
use App\Platform\FinancialLedger\LedgerReportResult;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * AC13: one bulk financial export — authorized, then re-authenticated, then
 * the export, then the audit. Domain logic stays in here and in
 * `LedgerReport`; the HTTP controller and the Filament page only mount this.
 *
 * ---------------------------------------------------------------------------
 * Authorization comes first, and it is query-level, not a boolean
 * ---------------------------------------------------------------------------
 * `Contracts\LedgerReadAuthorizer` resolves the reading actor from the
 * server-side `ActorContext` and returns the SET of badan usaha that actor
 * holds an active privileged grant for. That set is the report's filter. The
 * caller may pass `$entityRef` to narrow it, and the authorizer refuses a
 * reference outside the actor's grants rather than ignoring or widening it —
 * so no argument to this method can produce a report spanning books the actor
 * cannot see, and there is no "export everything" shape left to reach.
 *
 * `$actorRef`/`$actorRole` are deliberately NOT parameters. They used to be,
 * and the HTTP controller passed the session's own identifier plus the literal
 * role `authenticated_actor` — which meant the audit row recorded a role
 * nobody had granted, and a future non-HTTP caller could have named any actor
 * it liked. Both now come from `ActorContext` and the authorizer's verdict
 * (Wave 1b ruling, the discipline `ManualPayout` and `ResolveException`
 * already keep).
 *
 * Authorization runs BEFORE the re-authentication gate on purpose: an actor
 * with no finance authority at all should be refused without minting a
 * `reauthentication_events` challenge row for a request that could never have
 * succeeded.
 *
 * ---------------------------------------------------------------------------
 * The re-authentication gate, and what it does NOT currently prove
 * ---------------------------------------------------------------------------
 * This Action checks `reauthentication_events` for a satisfied event scoped to
 * the reason `bulk_financial_export` and inside the configured freshness
 * window — exactly the discipline `ManualPayout
 * ::assertApproverReauthenticatedRecently` establishes. The HTTP route
 * additionally carries `RequireRecentAuthentication`, which is a SESSION
 * FRESHNESS check over `ActorContext::$lastAuthenticatedAt`.
 *
 * The two are not the same check, and this Action's own gate is the stronger
 * one. It used to be vacuous over HTTP: the controller called
 * `ReauthenticationService::satisfy()` unconditionally on every request, which
 * verifies nothing, so it minted the very row this method then looked for. The
 * controller no longer does that. The consequence is honest and deliberate —
 * until a real step-up flow records a satisfied event for this reason (see the
 * carried-forward identity-lane gap: `MfaChallenge::submit()` does not call
 * `satisfy()` and does not refresh `last_authenticated_at`), this Action
 * refuses over HTTP. A refused financial export is the correct failure
 * direction; a fabricated proof is not.
 *
 * The reason string is a deliberate free string, not an enum: the module that
 * owns re-authentication reasons documents them as free strings (see
 * `RequireRecentAuthentication`'s and `ReauthenticationService::challenge()`'s
 * doc blocks), and `routes/web.php`'s middleware attachment names it
 * independently — this constant and that route argument are the two places it
 * is written, and they must match.
 *
 * ---------------------------------------------------------------------------
 * `BULK_FINANCIAL_EXPORT` audit with a null reason
 * ---------------------------------------------------------------------------
 * Every export records an audit row. `BULK_FINANCIAL_EXPORT` is NOT on
 * `SensitiveActions::ACTIONS` (the plan's Global Constraints fix this lane's
 * growth of that list at exactly `RECONCILIATION_EXCEPTION_RESOLVED`, landed
 * in Task 5), so `Audit::record()` accepts a null reason here — the export is a
 * read, and the report kind/period/scope label in `metadata.note` is all the
 * audit row needs. Metadata uses only `note`, a key already on
 * `MetadataAllowlist` (no amounts, no identity, no statement reference), and
 * the scope label is built from server-derived grant values, never from
 * unvalidated caller text.
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

    /**
     * `audit_events.subject_id` is `$table->string('subject_id')` —
     * `varchar(255)`. See `subjectReference()` for why this is enforced here
     * rather than left to the database.
     */
    private const int AUDIT_SUBJECT_MAX_LENGTH = 255;

    public function __construct(
        private readonly ActorContext $actorContext,
        private readonly LedgerReadAuthorizer $authorizer = new FinanceLedgerReadAuthorizer,
        private readonly ReauthenticationService $reauthentication = new ReauthenticationService,
        private readonly LedgerReport $report = new LedgerReport,
    ) {}

    /**
     * Produce one bulk financial export.
     *
     * @param  string  $kind  One of `LedgerReportKind::KNOWN_KINDS`.
     * @param  string  $period  `YYYY-MM`, the report period.
     * @param  string|null  $entityRef  An optional narrowing to one badan
     *                                  usaha. Never widens: it must name a badan usaha the
     *                                  server-resolved actor already holds a grant for.
     *
     * @throws LedgerReadNotAuthorisedException when the server-resolved actor
     *                                          holds no finance authority, or none over `$entityRef`.
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
        string $ip = '0.0.0.0',
        ?string $correlationId = null,
    ): BulkFinancialExportResult {
        LedgerReportKind::assertKnown($kind);

        // Normalise before validating, so this entry point and
        // `LedgerReport::summary()` (which has always trimmed) agree about a
        // padded period instead of one refusing what the other accepts — and
        // so the audit label below carries the normalised value.
        $period = trim($period);

        // Validate the period BEFORE any gate so a malformed request is
        // refused without recording a challenge it never should have needed —
        // same ordering discipline as `ManualPayout`, which validates inputs
        // before the freshness check.
        $this->report->assertPeriod($period);

        $scope = $this->authorizer->authorize($this->actorContext, $entityRef);
        $actorRef = (string) $this->actorContext->identityReference;

        $this->assertReauthenticatedRecently($actorRef, $scope->role, $ip);

        $result = $this->report->summary($period, $scope->entityRefs);

        $correlationId ??= app(CorrelationContext::class)->current()?->value;

        $debitTotal = array_sum(array_column($result->rows, 'debit_total'));
        $creditTotal = array_sum(array_column($result->rows, 'credit_total'));

        // The audit label: report kind, period, and the server-derived scope —
        // no amount, no identity, no statement reference, no free text.
        $reference = "{$kind}:{$period}:".$result->scopeLabel();

        Audit::record(
            action: self::AUDIT_ACTION,
            subject: new AuditSubject(
                'financial_ledger_report',
                $this->subjectReference($kind, $period, $reference, $result),
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $scope->role,
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
     * The amount columns are named `*_minor` and the file carries an explicit
     * `minor_units` column. They used to be `debit_total`/`credit_total`/`net`
     * beside a bare `IDR`, which reads as rupiah and is 100× the real value —
     * `config('money.minor_units')` is 2, so `journal_entries.amount_minor` is
     * sen. `Money::format()` is this module's canonical presenter and is used
     * on the human-facing report page, but deliberately NOT here: it emits
     * Indonesian formatting (`Rp 1.000,00`), whose thousands dot and decimal
     * comma corrupt exactly the spreadsheet import this file exists for. A
     * machine-readable artifact states its unit instead of formatting it, and
     * AC11's integer-minor-units-never-a-float discipline is preserved
     * literally.
     *
     * Every field is RFC 4180 quoted and escaped rather than concatenated, and
     * a leading `=`, `+`, `-` or `@` in a text field is neutralised with a
     * leading apostrophe — an account code is numeric today, but a downloadable
     * CSV opened in a spreadsheet is a formula-injection surface and this is
     * cheaper than relying on that staying true.
     *
     * @param  list<array{account_code: string, debit_total: int, credit_total: int, net: int}>  $rows
     */
    private function toCsv(array $rows, int $debitTotal, int $creditTotal): string
    {
        $minorUnits = (string) config('money.minor_units');

        $lines = [$this->toCsvLine([
            'account_code', 'debit_minor', 'credit_minor', 'net_minor', 'currency', 'minor_units',
        ])];

        foreach ($rows as $row) {
            $lines[] = $this->toCsvLine([
                $row['account_code'],
                (string) $row['debit_total'],
                (string) $row['credit_total'],
                (string) $row['net'],
                self::CURRENCY,
                $minorUnits,
            ]);
        }

        $lines[] = $this->toCsvLine([
            'TOTAL',
            (string) $debitTotal,
            (string) $creditTotal,
            (string) ($debitTotal - $creditTotal),
            self::CURRENCY,
            $minorUnits,
        ]);

        return implode("\n", $lines)."\n";
    }

    /**
     * The audit SUBJECT reference, bounded to fit `audit_events.subject_id`.
     *
     * That column is `varchar(255)` and PostgreSQL REJECTS an overflow rather
     * than truncating it, so an unbounded reference is not a cosmetic problem —
     * it fails the audit write, which fails the whole export, for a legitimately
     * authorized actor. Since the scope became a SET, the reference grew with
     * the actor's grant count: with `badan-usaha-NN`-length references the
     * ceiling is around 18 grants, and with UUID-shaped ones it is 7. Exactly
     * the multi-entity finance actor this module exists to serve would have hit
     * an uncaught 500.
     *
     * Bounded, but deliberately not lossy in either direction:
     *
     *  - while the full reference fits, it is used VERBATIM, so the ordinary
     *    one- and two-entity cases stay readable and greppable in the audit
     *    trail — the digest is a fallback, never the normal shape;
     *  - beyond that it becomes `{kind}:{period}:{N}-entities:{digest}`, which
     *    still states how many badan usaha were exported and is stable for a
     *    given set (the authorizer returns them sorted), so two exports of the
     *    same scope share a reference and can be correlated;
     *  - and the FULL list is written to `metadata.note` regardless. That is a
     *    nullable JSON column with no such limit, so no evidence is lost — only
     *    the indexed identifier is bounded.
     *
     * The length test is on BYTES (`strlen`), which is conservative: PostgreSQL
     * counts `varchar` in characters, and a string's byte length is always >=
     * its character length, so passing this check passes the column's.
     */
    private function subjectReference(
        string $kind,
        string $period,
        string $reference,
        LedgerReportResult $result,
    ): string {
        if (strlen($reference) <= self::AUDIT_SUBJECT_MAX_LENGTH) {
            return $reference;
        }

        $digest = substr(hash('sha256', $result->scopeLabel()), 0, 16);

        // `$kind`/`$period` deliberately, NOT `$result->kind`/`$result->period`:
        // `LedgerReport::summary()` hardcodes `kind: LedgerReportKind::SUMMARY`,
        // so reading the kind back off the result would make this branch always
        // say `summary` while the verbatim branch above — and the filename —
        // said whatever was asked for. Harmless while `KNOWN_KINDS` has one
        // member, and a silent divergence the moment a second one lands. Both
        // branches now derive from the same validated, normalised arguments.
        return "{$kind}:{$period}:{$result->scopeSize()}-entities:{$digest}";
    }

    /**
     * @param  list<string>  $fields
     */
    private function toCsvLine(array $fields): string
    {
        return implode(',', array_map(
            static function (string $field): string {
                // A negative amount legitimately starts with `-` and is a
                // number, not a formula — neutralising it would corrupt the
                // very values this file exists to carry.
                if (! is_numeric($field) && preg_match('/\A[=+\-@]/', $field) === 1) {
                    $field = "'".$field;
                }

                return '"'.str_replace('"', '""', $field).'"';
            },
            $fields,
        ));
    }

    private function assertReauthenticatedRecently(
        string $actorRef,
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

        throw BulkFinancialExportReauthenticationRequiredException::forActor($actorRef, $freshnessSeconds);
    }
}
