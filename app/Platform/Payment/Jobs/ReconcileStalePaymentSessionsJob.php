<?php

declare(strict_types=1);

namespace App\Platform\Payment\Jobs;

use App\Platform\Outbox\OutboxQueueName;
use App\Platform\Payment\Actions\ReconcilePaymentSession;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\SessionState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The scheduled-sweep half of the reconciliation feature — see
 * `Actions\ReconcilePaymentSession`'s class doc block for why it exists. The
 * on-return check (`Livewire\Public\Booking\BookingWizard`) only fires when
 * the customer's own browser comes back; this job is the safety net for
 * every case that never does — a closed tab, a network drop on the
 * redirect, a customer who pays via a QR code from a second device and
 * never returns to this one.
 *
 * ---------------------------------------------------------------------------
 * The staleness window
 * ---------------------------------------------------------------------------
 * Five minutes, not immediate: the on-return check already covers the fast
 * path, so this sweep does not need to be aggressive, and a payment session
 * genuinely is expected to sit at `AWAITING_PAYMENT` for the ordinary
 * seconds-to-minutes a customer takes to complete a QR scan. Sweeping too
 * soon would just repeat work the on-return check (or the real webhook,
 * which is still the fast, normal path — this sweep is a fallback, not a
 * replacement) already did. Five minutes is a starting point, not a tuned
 * value; `--minutes=` is not exposed on this job because it always runs
 * scheduled, but the threshold lives in one place (`self::STALE_AFTER_MINUTES`)
 * if it needs adjusting.
 *
 * ---------------------------------------------------------------------------
 * Error isolation — mirrors `DocumentVault\Jobs\ReconcileDocumentStorageCleanupJob`
 * ---------------------------------------------------------------------------
 * One session's `fetchStatus()` call failing (a provider timeout, a
 * transient network error) must never stop the sweep from reconciling every
 * other stale session. Each session is attempted independently; failures are
 * collected and the first one is re-thrown after every session has been
 * attempted, so the job's own retry/backoff still applies to a real,
 * persistent problem, but a single flaky call never blocks the batch.
 */
final class ReconcileStalePaymentSessionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const int STALE_AFTER_MINUTES = 5;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue(OutboxQueueName::Critical->value);
    }

    public function handle(ReconcilePaymentSession $reconcile): void
    {
        $failures = [];

        PaymentSession::query()
            ->where('state', SessionState::AwaitingPayment->value)
            ->where('created_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->cursor()
            ->each(function (PaymentSession $session) use ($reconcile, &$failures): void {
                try {
                    $reconcile($session);
                } catch (Throwable $exception) {
                    $failures[] = $exception;
                }
            });

        if ($failures !== []) {
            throw $failures[0];
        }
    }
}
