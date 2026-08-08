<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Illuminate\Support\Facades\DB;

/**
 * Creates a new, empty `booking_drafts` row at step 1 — the ONLY way this
 * module creates a draft. `booking-wizard-fields.md` §Global behavior:
 * "Draft created at first meaningful input" — callers invoke this the
 * moment the visitor makes their first Step 1 selection, never eagerly on
 * page load (see `App\Livewire\Public\Booking\BookingWizard::mount()`,
 * Task 9).
 *
 * Audited via `Audit::record()`, wrapped in its own `DB::transaction()` so
 * the draft row and its audit event commit or roll back together — this
 * plan's Global Constraints ("every domain-layer write... calls
 * `Audit::record()` inside its own transaction") and the same precedent as
 * `App\Domain\ServiceCatalog\Actions\DefineServicePackage`. Not
 * `SensitiveActions`-listed.
 */
final readonly class StartBookingDraft
{
    public function __invoke(?int $userId = null): BookingDraft
    {
        return DB::transaction(function () use ($userId): BookingDraft {
            $draft = BookingDraft::create([
                'user_id' => $userId,
            ]);

            Audit::record(
                action: 'BOOKING_DRAFT_STARTED',
                subject: new AuditSubject('booking_draft', $draft->id, $draft->version),
                outcome: AuditOutcome::Allowed,
                actorRef: $userId,
                actorRole: $userId !== null ? 'customer' : 'guest',
                source: AuditSource::Api,
            );

            return $draft;
        });
    }
}
