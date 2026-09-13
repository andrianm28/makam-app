<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IdentifiesConsoleOperator;
use App\Console\Commands\Concerns\RequiresAuditReason;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\Outbox\Models\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `php artisan outbox:replay {ids*} {--reason=}`
 *
 * The bounded, privileged, reason-required manual replay path
 * `docs/architecture/queue-and-outbox.md` §8 requires: "Outbox rows are
 * retained long enough for audit/replay policy. Manual replay requires
 * privileged permission, reason, and audit." Until this command, no code
 * path in this application could ever un-stick an outbox row once it left
 * the automatic claim/publish/backoff loop's reach — the only recovery for
 * a row an operator noticed had failed permanently (QUE-04:
 * `SpineWatchdogCommand`'s "dispatched but never consumed" signal, or a
 * `failed_jobs` entry for `PublishOutboxEventJob`) was to wait out
 * `OutboxPublisher::STALE_CLAIM_SECONDS` and hope the automatic reclaim
 * eventually succeeded, or hand-edit the database directly — an unaudited,
 * unreasoned, unbounded write this command replaces.
 *
 * ---------------------------------------------------------------------------
 * "Privileged" — console-only, exactly like `identity:*`
 * ---------------------------------------------------------------------------
 * No HTTP route, controller, Livewire component, or Filament surface wraps
 * this command, matching the `identity:grant-role` family's own ruling: a
 * replay that can re-fire a domain event a second time is an operator
 * decision, not a self-service action, and requiring shell access to the
 * host is the access control until a real console-authentication/RBAC layer
 * exists for CLI operators (see `IdentifiesConsoleOperator`'s own doc block
 * for exactly what that OS-account attribution does and does not prove).
 *
 * ---------------------------------------------------------------------------
 * "Bounded" — explicit ids only, capped per invocation
 * ---------------------------------------------------------------------------
 * This command NEVER replays "every stuck row" or accepts an unbounded
 * filter (a time window, a status, an event name) — an operator must name
 * the exact `outbox_events.id` values to replay, and at most
 * `self::MAX_IDS_PER_INVOCATION` of them at once. A blast-radius mistake
 * (fat-fingering a filter that matches thousands of rows) is structurally
 * impossible here; a large true backlog still needs multiple deliberate
 * invocations, which is the point — each is its own audited decision.
 *
 * ---------------------------------------------------------------------------
 * What "replay" actually does
 * ---------------------------------------------------------------------------
 * Only a row that has NOT already published (`dispatched_at IS NULL`) is
 * eligible — see the class doc block on `PublishOutboxEventJob` for why
 * `dispatched_at` is now the authoritative "did this actually publish"
 * marker (QUE-04). For each eligible id this command clears `locked_at`
 * (releasing any stale claim) and sets `available_at` to now, so the very
 * next `outbox:publish` tick claims and re-dispatches it through the
 * normal `OutboxPublisher`/`PublishOutboxEventJob` path — no bypass of the
 * claim/publish machinery, no direct event firing from inside this command.
 * `attempt_count`/`last_error` are left untouched: they are a record of
 * what already happened to this row, not something a replay should erase.
 *
 * An id that does not exist, or that has already published, is reported
 * and skipped — never silently ignored, and never a reason to abort ids
 * that ARE eligible.
 */
final class OutboxReplayCommand extends Command
{
    use IdentifiesConsoleOperator;
    use RequiresAuditReason;

    /**
     * The bounded-replay cap — see the class doc block's "Bounded" section.
     * A judgement call, not sourced from a specific figure in any cited
     * document: large enough that a genuine multi-row incident does not
     * need dozens of invocations, small enough that a single invocation
     * can never approach "replay everything."
     */
    public const int MAX_IDS_PER_INVOCATION = 50;

    protected $signature = 'outbox:replay
        {ids* : outbox_events.id values to replay (space-separated)}
        {--reason= : Mandatory justification for this manual replay, recorded on the audit trail}';

    protected $description = 'Manually re-queue specific stuck/failed outbox events for republication. Console-only, audited; --reason is mandatory.';

    public function handle(): int
    {
        $reason = (string) $this->option('reason');

        if ($this->reasonIsBlank($reason)) {
            $this->error('A non-blank --reason is required to replay an outbox event.');

            return self::FAILURE;
        }

        /** @var list<string> $ids */
        $ids = array_values(array_unique($this->argument('ids')));

        if ($ids === []) {
            $this->error('At least one outbox_events id must be given.');

            return self::FAILURE;
        }

        if (count($ids) > self::MAX_IDS_PER_INVOCATION) {
            $this->error(sprintf(
                'Refusing to replay %d ids in one invocation — the bounded-replay cap is %d. '.
                'Run this command again in smaller batches.',
                count($ids),
                self::MAX_IDS_PER_INVOCATION,
            ));

            return self::FAILURE;
        }

        $operator = $this->consoleOperatorRef();
        $replayed = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $result = $this->replayOne($id, $reason, $operator);

            if ($result) {
                $replayed++;
            } else {
                $skipped++;
            }
        }

        $this->info("Replayed {$replayed} outbox event(s); skipped {$skipped}.");

        return $replayed > 0 ? self::SUCCESS : self::FAILURE;
    }

    private function replayOne(string $id, string $reason, string $operator): bool
    {
        // `outbox_events.id` is a real Postgres `uuid` column: a
        // syntactically invalid value sent as a bind parameter is a
        // database ERROR (`invalid input syntax for type uuid`), not a
        // graceful "no rows" result the way it would be for a text/integer
        // key. Validate the shape BEFORE querying so a typo'd id is
        // reported and skipped like any other unknown id, rather than
        // throwing a raw SQL exception that aborts the whole invocation —
        // including every OTHER, valid id in the same batch.
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id) !== 1) {
            $this->warn("Skipped [{$id}]: not a valid outbox_events id (not a UUID).");

            return false;
        }

        $row = OutboxEvent::query()->find($id);

        if ($row === null) {
            $this->warn("Skipped [{$id}]: no such outbox event.");

            return false;
        }

        if ($row->dispatched_at !== null) {
            $this->warn("Skipped [{$id}]: already published (dispatched_at is set); replay is only for undispatched rows.");

            return false;
        }

        Audit::wrap(
            mutation: function () use ($row): void {
                $row->forceFill([
                    'locked_at' => null,
                    'available_at' => CarbonImmutable::now(),
                ])->save();
            },
            action: 'OUTBOX_EVENT_REPLAY',
            subject: fn (): AuditSubject => new AuditSubject('outbox_event', $row->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $operator,
            // `console:<os-account>` (see `IdentifiesConsoleOperator`) is
            // already the identity reference; `actorRole` follows the same
            // convention `identity:grant-role` established for console-only
            // commands — see `GrantActorRole`'s own doc block — rather than
            // inventing a fourth role label no `ActorRole::KNOWN_ROLES`
            // entry names.
            actorRole: ActorRole::SYSTEM,
            source: AuditSource::Console,
            reason: $reason,
            // 'note' is an EXISTING `MetadataAllowlist::ALLOWED_KEYS` key —
            // this command adds none. A closed-shape value only: the prior
            // attempt count this row carried into the replay. No event
            // name, no payload, no identifier (`AGENTS.md` §Observability).
            metadata: ['note' => "manual replay; prior attempt_count={$row->attempt_count}"],
        );

        $this->info("Replayed [{$id}]: available for the next outbox:publish tick.");

        return true;
    }
}
