<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Platform\FinancialLedger\Actions\BulkFinancialExport;
use App\Platform\FinancialLedger\Exceptions\BulkFinancialExportReauthenticationRequiredException;
use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\FinancialLedger\LedgerReportKind;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The download endpoint behind the finance report page's export button —
 * `BulkFinancialExport`'s HTTP mount point (see `routes/web.php` for the route,
 * its `RequireRecentAuthentication` attachment, and its throttle).
 *
 * This controller performs no financial logic and takes no authorization
 * decision of its own: `BulkFinancialExport` owns both the finance policy and
 * the re-authentication gate, so a future non-HTTP caller is gated identically.
 * All this does is normalise query input, hand it to the Action, and translate
 * the Action's two refusals into HTTP.
 *
 * ---------------------------------------------------------------------------
 * What this controller deliberately no longer does
 * ---------------------------------------------------------------------------
 * It used to call `ReauthenticationService::satisfy()` on every request, before
 * invoking the Action, copying `DisableMfaController`'s shape. `satisfy()`
 * verifies nothing — it unconditionally writes a `satisfied`
 * `reauthentication_events` row plus a `REAUTHENTICATION_SATISFIED` audit row
 * and clears the MFA rate-limit bucket. Doing that here had two consequences:
 * the Action's own gate could never fail over HTTP, because this controller had
 * just minted the exact row it looks for; and every export wrote an audit
 * record claiming the actor had re-proved their identity when in the normal
 * case they had merely logged in recently. In the module whose entire premise
 * is a trustworthy financial audit trail, a fabricated proof is worse than a
 * refusal. `satisfy()` belongs to whatever actually verifies a re-proof —
 * `MfaChallenge::submit()` — not to the action being protected.
 */
final class FinanceExportController extends Controller
{
    public function __invoke(
        Request $request,
        BulkFinancialExport $export,
    ): StreamedResponse|RedirectResponse {
        try {
            $result = $export->export(
                kind: $this->queryString($request, 'kind') ?? LedgerReportKind::SUMMARY,
                period: $this->queryString($request, 'period') ?? CarbonImmutable::now()->format('Y-m'),
                entityRef: $this->queryString($request, 'entity'),
                ip: $request->ip() ?? '0.0.0.0',
            );
        } catch (LedgerReadNotAuthorisedException) {
            abort(403);
        } catch (BulkFinancialExportReauthenticationRequiredException) {
            // Mirror `RequireRecentAuthentication`: preserve where the actor
            // was going and send them to the challenge page, rather than
            // surfacing a 500 for a control that fired exactly as intended.
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('filament.admin.pages.mfa-challenge');
        }

        return response()->streamDownload(
            static fn () => print ($result->contents),
            $result->filename,
            ['Content-Type' => $result->mimeType],
        );
    }

    /**
     * A query parameter as a string, or `null` when absent or not a scalar.
     *
     * An array-shaped parameter (`?entity[]=x`) is treated as absent rather
     * than cast — `(string) ['x']` yields the literal `Array`, which would then
     * travel into the authorizer and the audit trail as if it were a real badan
     * usaha reference.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
