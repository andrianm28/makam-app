<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditSource;
use App\Platform\FinancialLedger\Actions\BulkFinancialExport;
use App\Platform\FinancialLedger\LedgerReportKind;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The download endpoint behind the finance report page's export button —
 * `BulkFinancialExport`'s HTTP mount point (see `routes/web.php` for the
 * route and its `RequireRecentAuthentication` attachment).
 *
 * Mirrors `DisableMfaController` exactly on the re-authentication side:
 * reached only after `RequireRecentAuthentication`'s freshness check has
 * passed (either the actor's session was already fresh, or they just
 * completed `MfaChallenge` and were redirected back via the `url.intended`
 * key), it calls `ReauthenticationService::satisfy()` first to close that
 * challenge's audit trail — that is what makes the satisfied event the
 * `BulkFinancialExport` Action's own `reauthentication_events`-based check
 * finds. This controller performs no financial logic itself: it resolves
 * the actor from the authenticated session, hands the request to the
 * Action, and streams the Action's bytes.
 */
final class FinanceExportController extends Controller
{
    /**
     * Must match `BulkFinancialExport::REAUTHENTICATION_REASON` and the
     * `$reason` this controller's own route passes to
     * `RequireRecentAuthentication::class.':...'` in `routes/web.php`.
     */
    private const string REASON = 'bulk_financial_export';

    public function __invoke(
        Request $request,
        BulkFinancialExport $export,
        ReauthenticationService $reauthentication,
    ): StreamedResponse {
        $actorRef = (string) Auth::user()->getAuthIdentifier();
        $actorRole = 'authenticated_actor';

        // Close the challenge's audit trail before doing anything else — the
        // middleware may have just sent the actor through `MfaChallenge`.
        $reauthentication->satisfy(
            actorRef: $actorRef,
            actorRole: $actorRole,
            reason: self::REASON,
            source: AuditSource::Panel,
        );

        $result = $export->export(
            kind: (string) $request->query('kind', LedgerReportKind::SUMMARY),
            period: (string) $request->query('period', CarbonImmutable::now()->format('Y-m')),
            entityRef: $request->query('entity') !== null ? (string) $request->query('entity') : null,
            actorRef: $actorRef,
            actorRole: $actorRole,
            ip: $request->ip() ?? '0.0.0.0',
        );

        return response()->streamDownload(
            static fn () => print ($result->contents),
            $result->filename,
            ['Content-Type' => $result->mimeType],
        );
    }
}
