<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Actions;

use App\Domain\Memorial\Exceptions\MemorialVisitCheckInThrottledException;
use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\MemorialModerationState;
use App\Domain\Memorial\Models\MemorialVisitCheckin;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\IdentityAccess\ActorContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The self-service "Catat kunjungan" write path
 * (`docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md`
 * §4.2). Calls the EXISTING `ResolveMemorialQr` FIRST and lets
 * `MemorialNotVisibleException` propagate unmodified — every denial case
 * (gate closed, unknown/revoked token, unpublished, privacy) is refused
 * here exactly as it already is on the read path, never a second oracle.
 */
final readonly class LogMemorialVisitCheckIn
{
    /**
     * Concrete, load-bearing (there is no auth to rely on for abuse
     * prevention) — §4.3: enough for a handful of family members tapping
     * during one visit, not enough for sustained/scripted repetition.
     */
    public const int MAX_ATTEMPTS = 5;

    public const int DECAY_SECONDS = 900;

    public function __construct(
        private ResolveMemorialQr $resolveMemorialQr,
    ) {}

    public function __invoke(
        string $token,
        ?ActorContext $actor,
        ?string $visitorLabel,
        ?string $note,
        int|string $actorReference,
        string $actorRole,
        ?AuditSource $auditSource = null,
    ): MemorialVisitCheckin {
        $projection = ($this->resolveMemorialQr)($token, $actor);

        $key = 'memorial-visit-checkin:'.$token;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw MemorialVisitCheckInThrottledException::forToken($token, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return Audit::wrap(
            mutation: function () use ($projection, $visitorLabel, $note): MemorialVisitCheckin {
                $checkIn = MemorialVisitCheckin::query()->create([
                    'memorial_profile_id' => $projection->profileId,
                    'checked_in_at' => now(),
                    'visitor_label' => $visitorLabel,
                    'note' => $note,
                    'moderation_state' => MemorialModerationState::DEFAULT,
                ]);

                return $checkIn->fresh() ?? $checkIn;
            },
            action: MemorialAuditActions::MEMORIAL_VISIT_CHECKED_IN,
            subject: fn (MemorialVisitCheckin $row): AuditSubject => new AuditSubject('memorial_visit_checkin', $row->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource ?? AuditSource::Api,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
