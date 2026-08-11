<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The ONE write API for `audit_events` — `platform-audit` design.md's
 * "`AuditAdapter` (`overview.md` §5). One write API used by every
 * Action. Consumers never insert audit rows directly."
 *
 * ---------------------------------------------------------------------------
 * The only thing anything in this codebase is allowed to call to write
 * an audit row
 * ---------------------------------------------------------------------------
 * Every mutation that needs an audit trail calls either:
 *   - `Audit::record(...)` directly, when the mutation and the audit
 *     write are already inside the same transaction some other way; or
 *   - `Audit::wrap(...)` (below), which runs a mutation callable and
 *     `record()` inside one `DB::transaction()`, so the pair can never
 *     be committed separately (AC4, `tasks.md`: "Implement a
 *     mutation+audit wrapper so the pair cannot be separated.").
 *
 * NEVER call `AuditEvent::create()`/`::insert()`/`save()` directly —
 * see that model's own class-level doc block for exactly what
 * bypassing this class loses: AC2's required-field list, AC3's
 * sensitive-reason check, and AC5's metadata allowlist are all
 * enforced HERE, not on the model itself.
 *
 * ---------------------------------------------------------------------------
 * Why this is a plain static-method class, not a Laravel Facade or a
 * container binding
 * ---------------------------------------------------------------------------
 * Deliberately not bound in a service provider. Wiring a new binding
 * would mean adding a registration line to `bootstrap/providers.php`
 * (the pattern `IdentityAccessServiceProvider` already established) —
 * but that file is shared, and this batch's brief is explicit: "You
 * own exactly these files. Touch nothing else," with two sibling
 * agents concurrently building `app/Platform/FeatureGate/**` and
 * `app/Platform/IdentityAccess/Scopes/**` in their own worktrees right
 * now. Nothing here actually needs DI: every dependency
 * (`AuditEvent`, `DB`, `CarbonImmutable`) is either a static facade
 * already safe to call directly or a plain value object, so a plain
 * final class with static methods gives exactly the same "one API,
 * called the same way everywhere" property without touching a file
 * this batch does not own. Flagged explicitly as a deliberate choice,
 * not an oversight.
 */
final class Audit
{
    /**
     * Write exactly one `audit_events` row. Does NOT open its own
     * transaction — call this from inside an existing transaction (or
     * use `wrap()`, which provides one) to get AC4's "same transaction
     * as the state change" guarantee. Called on its own outside any
     * transaction, this still writes a single row atomically (a lone
     * `INSERT` already is atomic) — it just is not paired with any
     * other statement's commit/rollback.
     *
     * @param  string  $action  Free-text action name (e.g. 'DITOLAK',
     *                          'booking.updated'). Checked against
     *                          `SensitiveActions::ACTIONS` — see `$reason`.
     * @param  AuditSubject  $subject  AC5: a reference to the record this
     *                                 event is about, never its content.
     * @param  AuditOutcome  $outcome  allowed | denied | failed.
     * @param  int|string|null  $actorRef  AC2: the actor's identity reference
     *                                     (e.g. `ActorContext::$identityReference`). Null only for
     *                                     events with no actor identity to reference at all —
     *                                     `$actorRole` is still required even then (e.g. 'guest',
     *                                     'system').
     * @param  string  $actorRole  AC2: required for every event, including
     *                             one with a null `$actorRef`.
     * @param  AuditSource  $source  AC2: panel | api | job.
     * @param  string|null  $reason  AC3: required, and must carry readable
     *                               content — see `reasonIsBlank()` — when `$action` is on
     *                               `SensitiveActions::ACTIONS` — throws
     *                               `AuditReasonRequiredException` otherwise. Optional for
     *                               every other action.
     * @param  string|null  $correlationId  Schema column only — AC10's
     *                                      propagation mechanism is S3-T10, a separate later batch.
     *                                      Pass one through if the caller already has it; leave null
     *                                      otherwise.
     * @param  array<string, mixed>  $metadata  AC5: checked against
     *                                          `MetadataAllowlist::ALLOWED_KEYS` — throws
     *                                          `AuditMetadataKeyNotAllowedException` on any other key.
     */
    public static function record(
        string $action,
        AuditSubject $subject,
        AuditOutcome $outcome,
        int|string|null $actorRef,
        string $actorRole,
        AuditSource $source,
        ?string $reason = null,
        ?string $correlationId = null,
        array $metadata = [],
    ): AuditEvent {
        if (SensitiveActions::requiresReason($action) && self::reasonIsBlank($reason)) {
            throw AuditReasonRequiredException::forAction($action);
        }

        MetadataAllowlist::assertAllowed($metadata);

        return AuditEvent::create([
            'occurred_at' => CarbonImmutable::now(),
            'actor_ref' => $actorRef,
            'actor_role' => $actorRole,
            'action' => $action,
            'source' => $source->value,
            'subject_type' => $subject->type,
            'subject_id' => (string) $subject->id,
            'subject_version' => $subject->version !== null ? (string) $subject->version : null,
            'reason' => $reason,
            'correlation_id' => $correlationId,
            'outcome' => $outcome->value,
            'metadata' => $metadata,
        ]);
    }

    /**
     * AC3's authoritative blank-reason check for every action on
     * `SensitiveActions::ACTIONS`.
     *
     * Deliberately not `trim()`. `trim()` strips only the ASCII
     * whitespace set (" \t\n\r\0\x0B"), so `trim("\u{00A0}") === ''` is
     * `false` — a reason consisting solely of a non-breaking space, an
     * ideographic space (U+3000), or a zero-width space reads as a
     * non-blank justification while being invisible to a human reading
     * the audit trail. A refund or payout whose recorded justification
     * is one invisible character is indistinguishable, in review, from
     * one nobody authorised.
     *
     * `\p{Z}` covers Unicode separators and `\p{C}` covers
     * control/format characters, so this rejects the whole class rather
     * than playing whack-a-mole with individual code points. Only a
     * wholly-blank reason is rejected: prose containing a non-breaking
     * space between words is still accepted.
     *
     * The check fails closed. Under `/u`, `preg_match()` returns
     * `false` — not `0` — when the subject is not valid UTF-8, so the
     * result is compared against `0` rather than `1`: anything that is
     * not a clean "no match" counts as blank. A `=== 1` test would read
     * that `false` as "not blank" and let the action through. Bytes
     * that cannot be decoded cannot be read by a human reviewing the
     * audit trail either, so they are rejected rather than trusted.
     *
     * Known residual: the Hangul fillers (U+3164, U+1160, U+FFA0) are
     * category `Lo` (Letter, other), not `Z`/`C`, so a reason consisting
     * solely of them is still accepted despite rendering invisible in
     * most fonts. Enumerating those code points would trade away the
     * "reject the whole class, don't play whack-a-mole" property this
     * pattern is chosen for, so it is accepted and recorded here
     * instead.
     *
     * Same pattern as `Console\Commands\Concerns\RequiresAuditReason`,
     * which guards the four `identity:*` commands one layer above this
     * one. This is the authoritative check; that one is defence in
     * depth. Keep the two in step.
     */
    private static function reasonIsBlank(?string $reason): bool
    {
        if ($reason === null) {
            return true;
        }

        // `!== 0`, not `=== 1`: a `false` return must count as blank.
        return preg_match('/^[\p{Z}\p{C}\s]*$/u', $reason) !== 0;
    }

    /**
     * AC4: "WHEN a state change is committed THE SYSTEM SHALL write
     * its audit record in the same database transaction, such that no
     * committed state change exists without a corresponding audit
     * record." Runs `$mutation` and `record()` inside one
     * `DB::transaction()`.
     *
     * `$subject` may be the `AuditSubject` directly — when it is
     * already known before the mutation runs, e.g. an update or
     * delete on an existing record — or a closure receiving the
     * mutation's own return value, for when the subject is only known
     * AFTER the mutation runs, e.g. a newly created record's
     * autoincrement id.
     *
     * If `$mutation` throws, or `record()` itself throws (AC3's
     * missing-reason check, or AC5's metadata-allowlist check), the
     * whole transaction rolls back — the mutation's own database
     * change is undone along with the (never-written) audit row. See
     * `tests/Feature/Audit/AuditWrapTransactionTest.php` for the tests
     * that prove this for both failure sources.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $mutation
     * @param  AuditSubject|Closure(TResult): AuditSubject  $subject
     * @param  array<string, mixed>  $metadata
     * @return TResult
     */
    public static function wrap(
        Closure $mutation,
        string $action,
        AuditSubject|Closure $subject,
        AuditOutcome $outcome,
        int|string|null $actorRef,
        string $actorRole,
        AuditSource $source,
        ?string $reason = null,
        ?string $correlationId = null,
        array $metadata = [],
    ): mixed {
        return DB::transaction(function () use (
            $mutation,
            $action,
            $subject,
            $outcome,
            $actorRef,
            $actorRole,
            $source,
            $reason,
            $correlationId,
            $metadata,
        ) {
            $result = $mutation();

            $resolvedSubject = $subject instanceof Closure ? $subject($result) : $subject;

            self::record(
                action: $action,
                subject: $resolvedSubject,
                outcome: $outcome,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: $source,
                reason: $reason,
                correlationId: $correlationId,
                metadata: $metadata,
            );

            return $result;
        });
    }
}
