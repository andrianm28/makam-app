# Outbox Retrofit + Booking-Draft Producer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the already-shipped `app/Platform/Outbox/**` module a real Superpowers SDD retrofit (two-tier review, bounded fix wave, documentation correction) and, in the same unit of work, wire its second and third real event producers on the booking-draft path so `platform-outbox` AC1 is finally proved against a real domain mutation instead of a test fixture.

**Architecture:** The Outbox module is infrastructure that is architecturally sound but has never actually run. Its one existing producer (`GateActivationRecorder`) has no caller anywhere in the repo, so `outbox_events` has zero rows in every deployed environment. This plan adds an `Outbox::record()` call next to the `Audit::record()` call that already exists inside the already-existing `DB::transaction()` of two `app/Domain/Booking/Actions/` classes — no new files, no new transaction boundaries, no schema change — and then reviews the whole module against that new reality.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, PostgreSQL 18, Pest/PHPUnit. `SELECT ... FOR UPDATE SKIP LOCKED` is Postgres-only by design; the module throws a clear error on SQLite rather than degrading.

---

## Current state — read this before planning any change

This section is the factual baseline. Every claim below was verified directly in this worktree at plan time; nothing is carried over from a prior document's summary.

### What is already built

`app/Platform/Outbox/` contains 8 real files, none of them scaffolding:

| File | What it actually does |
|---|---|
| `Outbox.php` | The one write API. `Outbox::record()` inserts a single `outbox_events` row. Deliberately opens no transaction of its own (same discipline as `Audit::record()`); reads `trace_id` itself from `CorrelationContext` at insert time. |
| `OutboxPublisher.php` | The real `SELECT ... FOR UPDATE SKIP LOCKED` claim loop with bounded-backoff retry and stale-claim reclaim. |
| `PayloadClassification.php` | AC7 denylist (`DENYLISTED_KEYS`, 17 key names), checked recursively at any nesting depth, throwing `OutboxPayloadKeyNotAllowedException`. |
| `OutboxQueueRouter.php` | AC8 event-name → queue table. Exactly 3 routes, each with a quotable `queue-and-outbox.md` §2 justification; everything else falls back to `default`. |
| `OutboxClassification.php`, `OutboxQueueName.php` | Closed lists. |
| `Models/OutboxEvent.php`, `Jobs/PublishOutboxEventJob.php`, `Events/OutboxEventPublished.php`, `Exceptions/OutboxPayloadKeyNotAllowedException.php` | Model, queue job, framework event, exception. |

Six real test files exist under `tests/Feature/Outbox/`: `OutboxCorrelationTest`, `OutboxRecoveryTest`, `OutboxTransactionTest`, `OutboxPayloadClassificationTest`, `OutboxPublisherClaimTest`, `OutboxQueueRoutingTest`.

### The status this retrofit starts from

`docs/planning/sprint-plan.md:555` (row **S3-T11**) is the authoritative status. It records ⚠️ **partial**, done 26 Jul 2026 (Batch 3.4), and is unusually honest about why. Its four self-flagged gaps, quoted:

1. **AC1** "is proved only against a `tests/Fixtures/` aggregate, not a real domain mutation (none exists yet — same honesty gap N-9/S3-T10 already established)."
2. **AC5**'s "atomic-claim proof is sequential-only — this suite's `RefreshDatabase`-per-test transaction wrapping means a genuinely separate second database session cannot see this test's uncommitted fixture rows."
3. **AC8**'s "routing correctness is proved but starvation PREVENTION under load needs Sprint 6's Horizon supervisor pools, not built here."
4. **AC9, AC11, AC12** "are explicitly out of scope per the execution plan and finding N-8."

**This plan closes gap 1 and only gap 1.** Gaps 2, 3, and 4 are structural (test-harness limits, unbuilt Horizon pools, out-of-scope ACs) and get an explicit ledgered disposition in Task 5, not a fix.

### The two documentation defects this retrofit must correct

**Defect A — `.kiro/specs/platform-outbox/tasks.md` ends with a literally false section:**

```
## NOT TESTED

Nothing here is implemented. No Redis queue, no Horizon, no worker exists.
```

This was true on 25 Jul 2026 and became false on 26 Jul 2026 when Batch 3.4 landed. Eight source files and six test files now exist. Every one of the 16 task checkboxes in that file is also still unchecked, including the five that Batch 3.4 genuinely completed.

**Defect B — the module has never fired in production.** `outbox_events` was live-queried at 0 rows in every deployed environment. This is not a bug in the module; it is the direct consequence of its only producer, `GateActivationRecorder::record()`, having no caller. `grep -rn "GateActivationRecorder" app/` finds the class and its own tests, and nothing else — no controller, no Livewire component, no Filament action, no console command invokes it. The admin gate-management screen that would call it is unbuilt.

---

## Scope decision: which producer gets wired, and why

The human has pre-cleared the **booking-draft path** (`app/Domain/Booking/Actions/`) specifically because it is routine customer input, not security-sensitive, and therefore needs no additional sign-off under `AGENTS.md` §Infrastructure-agent execution. The alternative candidate (MFA enrolment confirm/revoke) was rejected at plan time for exactly that reason and is not revisited here.

**Decision: wire BOTH `StartBookingDraft` and `SaveBookingDraftStep`.**

The brief left this open ("decide between them — or wire both if that's cleanly scoped"). Both, for four reasons:

1. **They are the only producers in this repo with a real, routed caller.** `app/Livewire/Public/Booking/BookingWizard.php` imports and invokes both (`BookingWizard.php:7-8`, `:201`). This is the decisive difference from `GateActivationRecorder` — wiring a producer whose caller is also unbuilt would reproduce the exact defect this retrofit exists to fix, just one class over.
2. **They give complementary proofs.** `StartBookingDraft` fires exactly once per journey — a clean aggregate-lifecycle event. `SaveBookingDraftStep` fires up to four times per journey — the throughput proof the publisher's claim loop needs, and the only way to prove multiple pending events for the same aggregate are claimed and dispatched independently.
3. **`SaveBookingDraftStep` yields an idempotency proof for free.** It already returns early on an idempotency-key replay (`SaveBookingDraftStep.php:69-71`), before its transaction opens. So a replayed save must produce **no second outbox row** — a real, behavioural no-duplicate assertion that needs no new production code to make true.
4. **The increment over wiring just one is ~15 lines.** Each change is an `Outbox::record()` call placed beside the `Audit::record()` call that is already inside each Action's already-existing `DB::transaction()`. No new files, no new transaction boundaries, no migration, no schema change.

### Two gaps this scope decision knowingly opens — disclosed, not papered over

**Gap 1 — the event names are uncatalogued.** `docs/contracts/event-catalog.md` has no entry for a booking-draft-started or booking-draft-step-saved event. Its one booking row is `booking.draft_submitted.v2` (producer: Booking), which is a *submission* event belonging to Step 9 — and Step 9 does not exist: `BookingWizardStep::LAST_IMPLEMENTED` is 5, and `SaveBookingDraftStep` throws `InvalidArgumentException` for any step above it. So the catalogued booking event is genuinely unproducible today.

This is the **same disclosed-gap pattern already established for finding N-12** (`docs/planning/sprint-plan.md`), when `GateActivationRecorder` needed `feature_gate.state_changed.v1` and the catalogue had no gate-state-change entry. That precedent's reasoning applies verbatim and is followed here: AC3 ("use event types from `event-catalog.md` and SHALL NOT restate the catalogue") constrains the outbox module's own general behaviour and forbids this module from unilaterally inventing catalogue entries; it does not forbid a producer from emitting an event. So: the rows are written, using clearly-provisional names that follow the catalogue's own `noun.verb_past_tense.vN` convention, and the gap is recorded as a new numbered finding rather than silently absorbed. **Task 5 records it as finding N-17.**

**Corrected 09 Aug 2026 (Task 1 review finding).** This plan originally said "finding N-14 (next free number — N-13 is the last one on `sprint-plan.md`)". That was wrong on both counts. `sprint-plan.md`'s Appendix A already runs to **N-16**: N-14 is the 26 Jul FAQ/Blade-compiler `@php`-in-doc-comment post-mortem (still referenced by name in `app/Console/Commands/VerifyBladeContentSurvivalCommand.php`), N-15 is the OQ-05 icon-set gap, and N-16 is the unimplemented `openapi.yaml`. The next genuinely free number is **N-17**, and every reference in this plan and in production code must cite N-17, not N-14. Do not touch `VerifyBladeContentSurvivalCommand.php`'s N-14 mentions — those correctly refer to the real N-14.

Chosen names, derived from the audit action names those same two Actions already write (`BOOKING_DRAFT_STARTED`, `BOOKING_DRAFT_STEP_SAVED`) so they are translations of existing vocabulary, not inventions:

- `booking.draft_started.v1`
- `booking.draft_step_saved.v1`

**Gap 2 — neither event is "critical" in AC1's sense.** AC1 reads "WHEN a **critical** domain event occurs." A booking draft starting, or one wizard step being saved, is routine customer input — the same property that makes it safe to wire without sign-off is the property that makes it non-critical. The genuinely critical booking event is `booking.draft_submitted.v2` (money path, order creation), and it is unbuildable today per Gap 1.

This plan does not claim AC1 is now fully satisfied. It claims something narrower and true: **AC1's mechanism is now proved end-to-end against a real domain mutation with a real caller, instead of against `tests/Fixtures/OutboxFixtureAggregate.php`.** The remaining distance — proving it on an event that is actually critical — belongs to whichever spec builds Step 9 submission. Task 5 ledgers this explicitly. Reviewers must not treat this disclosure as a defect to fix in the fix wave; it is a scope boundary recorded on purpose.

### Deliberately NOT changed

- **`OutboxQueueRouter::ROUTES` gets no new entry.** Both new events correctly fall back to `DEFAULT_QUEUE` (`default`). That class's own doc block sets the bar for adding a route: "add one line to `ROUTES` citing the specific `queue-and-outbox.md` §2 text that justifies the queue choice." No §2 text covers booking drafts, so adding a route would be a guess. An unmapped event defaulting to `default` is the documented correct behaviour, not an omission.
- **No migration.** `outbox_events` already exists and is already deployed. Any schema change here would trip `AGENTS.md` §Infrastructure-agent execution's human-review requirement.
- **`SensitiveActions`** is untouched. Neither Action is sensitive; both already say so in their own doc blocks.
- **No new UI, no `BookingWizard` change.** The Livewire component already calls both Actions. Wiring happens strictly inside the domain layer.

---

## Global Constraints

Copied verbatim from the governing documents; every task's requirements implicitly include these.

- `AGENTS.md` §Queue and event reliability: "Critical domain events are inserted into the transactional outbox in the same database transaction as state mutation." Every `Outbox::record()` call in this plan goes **inside** the caller's existing `DB::transaction()`, never before or after it.
- `AGENTS.md` §Observability: "Never place restricted data in logs, Pulse, Horizon tags, or error trackers." Outbox payloads carry aggregate **references only** — AC2's "a reference to the aggregate this event is about — never its content."
- `AGENTS.md` §Infrastructure-agent execution: "Never report `PASS` for a check that was not executed; use `BLOCKED` or `NOT TESTED` explicitly."
- `AGENTS.md` §Testing: "Every bug fix requires regression test."
- `AGENTS.md` §Documentation: "Do not duplicate canonical catalog data in multiple hand-maintained documents or code locations."
- **Host constraint** (`CLAUDE.md` §Scope note): `vendor/` is empty on this host. Never run `composer install` or `npm run build` here. Local verification is `php -l` plus `bash ci/verify-docs.sh`. The real `php artisan test` run happens in CI against PostgreSQL 18. Any test result not actually executed is reported `NOT TESTED`, never `PASS`.
- **Postgres-only by design:** the claim loop requires a real Postgres connection and throws a clear error on SQLite. Do not attempt a local `SKIP LOCKED` proof; that is CI's job.
- Plan doc is committed before any implementation begins.

---

## File Structure

| File | Change | Responsibility after the change |
|---|---|---|
| `app/Domain/Booking/Actions/StartBookingDraft.php` | Modify | Unchanged responsibility (create a draft, audited). Gains one `Outbox::record()` call inside its existing transaction, plus doc-block reasoning. |
| `app/Domain/Booking/Actions/SaveBookingDraftStep.php` | Modify | Unchanged responsibility (validate + persist one step, audited). Gains one `Outbox::record()` call inside its existing transaction, plus doc-block reasoning. |
| `tests/Feature/Domain/Booking/Actions/BookingDraftOutboxTest.php` | Create | Producer-side proof: correct row shape for both events, rollback-on-failure in both directions, no duplicate row on idempotency replay. |
| `tests/Feature/Outbox/OutboxBookingDraftPublicationTest.php` | Create | The AC1 end-to-end proof: real domain mutation → real `outbox_events` row → `OutboxPublisher` claim → `PublishOutboxEventJob` dispatch. Lives under `tests/Feature/Outbox/` because it is the outbox module's proof, not booking's. |
| `.kiro/specs/platform-outbox/tasks.md` | Modify (Task 5) | Correct the false "NOT TESTED / nothing is implemented" section and the checkbox states. |
| `.kiro/specs/platform-outbox/design.md` | Modify (Task 5) | Record dispositions surfaced by review. |
| `docs/planning/sprint-plan.md` | Append-correct (Task 5) | S3-T11 row + new finding N-17. Never rewrite prior text. |
| `docs/planning/retrofit-backlog.md` | Append-correct (Task 5) | §1 item 6 status + new §2 entry. |

**Test placement rule for implementers:** producer-side behaviour (does the Action write the right row, does it roll back) goes in the Booking test. Publisher-side behaviour (does the claim loop pick the row up and dispatch it) goes in the Outbox test. Do not merge them — they fail for different reasons and belong to different modules' review slices.

---

## Task 1: Wire `StartBookingDraft` as a real outbox producer

**Files:**
- Modify: `app/Domain/Booking/Actions/StartBookingDraft.php:33-48`
- Test: `tests/Feature/Domain/Booking/Actions/BookingDraftOutboxTest.php` (create)

**Interfaces:**
- Consumes: `App\Platform\Outbox\Outbox::record(string $eventName, int $eventVersion, string $aggregateType, int|string $aggregateId, array $data, OutboxClassification $classification, ?string $idempotencyKey = null): OutboxEvent` and `App\Platform\Outbox\OutboxClassification::Internal`.
- Produces: an `outbox_events` row with `event_name = 'booking.draft_started.v1'`, `event_version = 1`, `aggregate_type = 'booking_draft'`, `aggregate_id = (string) $draft->id`, `idempotency_key = "booking_draft:{$draft->id}:started"`. Task 3's end-to-end test relies on these exact values.

**Payload shape (fixed here, do not vary):**

```php
[
    'draft_id' => $draft->id,
    'actor_role' => $userId !== null ? 'customer' : 'guest',
    'started_at' => $draft->created_at->toIso8601String(),
]
```

**Note for the implementer:** `BookingDraft` uses `HasUuids`, so `$draft->id` is a **UUID string**, not an integer. Do not cast it to `int` anywhere. `Outbox::record()`'s `$aggregateId` accepts `int|string` and stringifies internally.

`actor_role` is a role string, never an identifier — `user_id` is deliberately excluded. `outbox_events` has no `actor_type`/`actor_id` columns by design (finding N-11), and a bare role carries no personal data, satisfying AC2's references-only rule without needing `PayloadClassification` to save us.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Domain/Booking/Actions/BookingDraftOutboxTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Platform\Outbox\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BookingDraftOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_draft_writes_one_outbox_event_with_the_agreed_shape(): void
    {
        $draft = (new StartBookingDraft)(userId: null);

        $events = OutboxEvent::query()->where('event_name', 'booking.draft_started.v1')->get();

        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertSame(1, $event->event_version);
        $this->assertSame('booking_draft', $event->aggregate_type);
        $this->assertSame((string) $draft->id, $event->aggregate_id);
        $this->assertSame("booking_draft:{$draft->id}:started", $event->idempotency_key);
        $this->assertSame('INTERNAL', $event->classification);
        $this->assertSame($draft->id, $event->payload['draft_id']);
        $this->assertSame('guest', $event->payload['actor_role']);
        $this->assertArrayHasKey('started_at', $event->payload);
        $this->assertArrayNotHasKey('user_id', $event->payload);
    }

    public function test_an_authenticated_start_records_the_customer_role_not_the_identifier(): void
    {
        (new StartBookingDraft)(userId: 4242);

        $event = OutboxEvent::query()->where('event_name', 'booking.draft_started.v1')->sole();

        $this->assertSame('customer', $event->payload['actor_role']);
        // The identifier itself must never reach the payload — only the role.
        $this->assertArrayNotHasKey('user_id', $event->payload);
        $this->assertNotContains(4242, $event->payload, 'The user identifier must not appear under any key.');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BookingDraftOutboxTest`
Expected on this host: **BLOCKED** — `vendor/` is empty, no local Postgres. Report `NOT TESTED`, do not report PASS or FAIL. Verify syntax instead with `php -l tests/Feature/Domain/Booking/Actions/BookingDraftOutboxTest.php`. The genuine RED/GREEN transition is observed in CI on the branch's first push.

- [ ] **Step 3: Write the minimal implementation**

In `StartBookingDraft.php`, add the imports `App\Platform\Outbox\Outbox` and `App\Platform\Outbox\OutboxClassification`, then insert this **between** the `BookingDraft::create()` call and the existing `Audit::record()` call, inside the existing `DB::transaction()` closure:

```php
            // `platform-outbox` AC1: the event and the state mutation commit
            // or roll back together. Inside the transaction this Action
            // already opened — deliberately not a second one.
            //
            // Event name: `docs/contracts/event-catalog.md` has no entry for
            // a booking-draft-started event. Its one booking row is
            // `booking.draft_submitted.v2`, a Step 9 SUBMISSION event, and
            // Step 9 is unbuilt (`BookingWizardStep::LAST_IMPLEMENTED` is 5).
            // Per AC3 this Action does not invent a catalogue entry; it emits
            // a clearly-provisional name following the catalogue's own
            // `noun.verb_past_tense.vN` convention, and the gap is recorded
            // as finding N-17 in `docs/planning/sprint-plan.md` — the same
            // disclosed-gap treatment finding N-12 already applied to
            // `feature_gate.state_changed.v1`.
            Outbox::record(
                eventName: 'booking.draft_started.v1',
                eventVersion: 1,
                aggregateType: 'booking_draft',
                aggregateId: $draft->id,
                data: [
                    'draft_id' => $draft->id,
                    // A role, never an identifier. AC2 is references-only and
                    // `outbox_events` has no actor columns by design (finding
                    // N-11) — `user_id` is deliberately absent.
                    'actor_role' => $userId !== null ? 'customer' : 'guest',
                    'started_at' => $draft->created_at->toIso8601String(),
                ],
                classification: OutboxClassification::Internal,
                // One draft can only be started once, so the aggregate id is
                // itself the natural uniqueness key here — unlike
                // `GateActivationRecorder`, where the same gate legitimately
                // changes state many times and the key had to be per-activation.
                idempotencyKey: "booking_draft:{$draft->id}:started",
            );
```

- [ ] **Step 4: Verify**

Run: `php -l app/Domain/Booking/Actions/StartBookingDraft.php` — expect "No syntax errors detected".
Run: `bash ci/verify-docs.sh` — expect ALL DOC GATES PASS.
Test execution: `NOT TESTED` on this host (see Step 2); CI is the real gate.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Booking/Actions/StartBookingDraft.php tests/Feature/Domain/Booking/Actions/BookingDraftOutboxTest.php
git commit -m "feat(booking): emit booking.draft_started.v1 to the transactional outbox"
```

---

## Task 2: Wire `SaveBookingDraftStep` as a real outbox producer

**Files:**
- Modify: `app/Domain/Booking/Actions/SaveBookingDraftStep.php:95-138`
- Test: `tests/Feature/Domain/Booking/Actions/BookingDraftOutboxTest.php` (extend)

**Interfaces:**
- Consumes: the same `Outbox::record()` signature as Task 1.
- Produces: an `outbox_events` row per non-replayed step save, with `event_name = 'booking.draft_step_saved.v1'`, `aggregate_type = 'booking_draft'`, `idempotency_key = "booking_draft:{$current->id}:step:{$step}:v{$current->version}"`. Task 3 relies on multiple such rows existing for one aggregate.

**Payload shape (fixed here, do not vary):**

```php
[
    'draft_id' => $current->id,
    'step' => $step,
    'version' => $current->version,
    'completed_steps' => $current->completed_steps,
]
```

**Why the step's own `$payload` is not forwarded:** AC2 is "a reference to the aggregate this event is about — never its content." The step payload is content (city, cemetery, service selections). A consumer that needs it reads the draft by `draft_id`. This also keeps the event immune to a future step introducing a restricted field, rather than relying on `PayloadClassification`'s key-name denylist to catch it — that denylist is explicitly documented as "not a substitute for producers themselves following references-only."

**Why the version is in the idempotency key:** `version` is bumped exactly once per accepted save, so `draft:step:version` is unique per real save while still being deterministic. Step alone is not unique — back-navigation legitimately re-saves the same step (AC11), and each re-save is a distinct event, not a replay.

**Note for the implementer (corrected 09 Aug 2026 against the real domain):** `booking_drafts.version` **defaults to 1**, not 0 (`BookingDraft::$attributes['version'] = 1` and the migration's `->default(1)`). `SaveBookingDraftStep` sets `version = $current->version + 1`, so after the FIRST accepted save `version` is **2**, and this event's idempotency key is therefore `booking_draft:{id}:step:1:v2`. Do not assume a first save yields `v1`.

**Note for the implementer (corrected 09 Aug 2026 against the real domain):** the only valid `city_code` values are `LaunchCityCode::KNOWN_CODES` — `JAKARTA`, `BOGOR`, `DEPOK`, `TANGERANG`, `BEKASI`. `'JKT'` is **not** one of them and would throw `BookingStepValidationException`. Use `'JAKARTA'` in every test payload below.

- [ ] **Step 1: Write the failing tests**

Append these three methods to `BookingDraftOutboxTest`, and add `use App\Domain\Booking\Actions\SaveBookingDraftStep;` plus `use App\Domain\Booking\Models\BookingDraft;` to its imports:

```php
    public function test_saving_a_step_writes_one_outbox_event_referencing_the_draft_not_its_content(): void
    {
        $draft = (new StartBookingDraft)(userId: null);

        (new SaveBookingDraftStep)($draft, 1, ['city_code' => 'JAKARTA'], 'key-1');

        $event = OutboxEvent::query()->where('event_name', 'booking.draft_step_saved.v1')->sole();

        $this->assertSame('booking_draft', $event->aggregate_type);
        $this->assertSame((string) $draft->id, $event->aggregate_id);
        $this->assertSame(1, $event->payload['step']);
        $this->assertSame($draft->id, $event->payload['draft_id']);
        $this->assertSame([1], $event->payload['completed_steps']);
        // AC2: reference, never content. The city belongs to the draft row.
        $this->assertArrayNotHasKey('city_code', $event->payload);
    }

    public function test_an_idempotent_replay_does_not_write_a_second_outbox_event(): void
    {
        $draft = (new StartBookingDraft)(userId: null);

        $saved = (new SaveBookingDraftStep)($draft, 1, ['city_code' => 'JAKARTA'], 'replayed-key');
        (new SaveBookingDraftStep)($saved, 1, ['city_code' => 'JAKARTA'], 'replayed-key');

        $this->assertSame(
            1,
            OutboxEvent::query()->where('event_name', 'booking.draft_step_saved.v1')->count()
        );
    }

    public function test_a_rejected_step_save_writes_no_outbox_event_at_all(): void
    {
        $draft = (new StartBookingDraft)(userId: null);

        try {
            (new SaveBookingDraftStep)($draft, 1, ['city_code' => 'NOT_A_CITY'], 'key-1');
            $this->fail('Expected the invalid city to be rejected.');
        } catch (\App\Domain\Booking\Exceptions\BookingStepValidationException) {
            // expected
        }

        $this->assertSame(
            0,
            OutboxEvent::query()->where('event_name', 'booking.draft_step_saved.v1')->count()
        );
    }
```

Note for the implementer: validation runs *before* the transaction opens, so the last test proves the ordering is right, not the rollback. The rollback-direction proof is Task 4's job and is deliberately not duplicated here.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=BookingDraftOutboxTest`
Expected on this host: **BLOCKED / NOT TESTED**, same as Task 1 Step 2. Syntax-check only.

- [ ] **Step 3: Write the minimal implementation**

In `SaveBookingDraftStep.php`, add the imports `App\Platform\Outbox\Outbox` and `App\Platform\Outbox\OutboxClassification`, then insert this immediately **before** the existing `Audit::record()` call (after `$current->save()`), inside the existing `DB::transaction()` closure:

```php
            // `platform-outbox` AC1 — same transaction as the save above.
            // Provisional event name; see `StartBookingDraft`'s own note and
            // finding N-17 for why `event-catalog.md` has no entry to use.
            Outbox::record(
                eventName: 'booking.draft_step_saved.v1',
                eventVersion: 1,
                aggregateType: 'booking_draft',
                aggregateId: $current->id,
                data: [
                    'draft_id' => $current->id,
                    'step' => $step,
                    'version' => $current->version,
                    'completed_steps' => $current->completed_steps,
                    // AC2 is references-only: the step's own `$payload`
                    // (city, cemetery, service selections) is CONTENT and is
                    // deliberately not forwarded. A consumer reads the draft
                    // by `draft_id`. This also keeps the event immune to a
                    // future step adding a restricted field, rather than
                    // relying on `PayloadClassification`'s key-name denylist
                    // — which its own doc block says is "not a substitute for
                    // producers themselves following references-only."
                ],
                classification: OutboxClassification::Internal,
                // `version` bumps exactly once per accepted save, so this is
                // unique per real save yet deterministic. Step alone is not
                // unique: back-navigation legitimately re-saves a step (AC11)
                // and each re-save is a distinct event, not a replay. A true
                // replay never reaches here — it returns early above, before
                // this transaction opens.
                idempotencyKey: "booking_draft:{$current->id}:step:{$step}:v{$current->version}",
            );
```

- [ ] **Step 4: Verify**

Run: `php -l app/Domain/Booking/Actions/SaveBookingDraftStep.php` — expect "No syntax errors detected".
Run: `bash ci/verify-docs.sh` — expect ALL DOC GATES PASS.
Test execution: `NOT TESTED` on this host.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Booking/Actions/SaveBookingDraftStep.php tests/Feature/Domain/Booking/Actions/BookingDraftOutboxTest.php
git commit -m "feat(booking): emit booking.draft_step_saved.v1 to the transactional outbox"
```

---

## Task 3: The AC1 end-to-end publication proof

This is the task that actually closes S3-T11's gap 1. Everything before it only writes rows; this proves a row written by a **real domain mutation** is claimed and dispatched by the real publisher.

**Files:**
- Create: `tests/Feature/Outbox/OutboxBookingDraftPublicationTest.php`

**Interfaces:**
- Consumes: Task 1's `booking.draft_started.v1` row shape and Task 2's `booking.draft_step_saved.v1` row shape, exactly as specified above.
- Produces: nothing other tasks consume.

**Publisher API (verified at plan time, use exactly this):** `App\Platform\Outbox\OutboxPublisher::publishBatch(int $batchSize = self::DEFAULT_BATCH_SIZE): int` — returns the number of events claimed and dispatched. `DEFAULT_BATCH_SIZE` is 50, so the default covers every case in this test.

**Corrected 09 Aug 2026 against the real module — two things the draft test code below gets wrong, fix both:**

1. **Construction idiom.** `tests/Feature/Outbox/OutboxRecoveryTest.php:114` already establishes `(new OutboxPublisher)->publishBatch()`. Use that, **not** `app(OutboxPublisher::class)`, per this plan's own "follow it rather than inventing a second one."
2. **This test MUST carry the Postgres guard.** `OutboxPublisher::claim()` does not skip on a non-Postgres driver — it **throws `RuntimeException`**. Without a guard this test hard-fails anywhere `DB_CONNECTION` is not `pgsql`. Copy `OutboxRecoveryTest`'s `setUp()` idiom verbatim:

```php
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'OutboxPublisher::claim() requires real Postgres row locking '.
                '(SELECT ... FOR UPDATE SKIP LOCKED). Run with DB_CONNECTION=pgsql, as CI does.'
            );
        }
    }
```

Add `use Illuminate\Support\Facades\DB;` for it. Note the third test (`..._route_to_the_default_queue`) is pure routing and needs no database — but it inherits the skip, which is acceptable: `OutboxQueueRoutingTest` already covers routing on every driver.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Platform\Outbox\Jobs\PublishOutboxEventJob;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\OutboxQueueName;
use App\Platform\Outbox\OutboxQueueRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Closes `docs/planning/sprint-plan.md` S3-T11's first self-flagged gap:
 * "AC1 is proved only against a `tests/Fixtures/` aggregate, not a real
 * domain mutation (none exists yet)." One now exists. Every row this test
 * publishes was written by `app/Domain/Booking/Actions/**` inside that
 * Action's own transaction, triggered the same way the real
 * `BookingWizard` Livewire component triggers it.
 *
 * What this does NOT prove, deliberately: cross-session `SKIP LOCKED`
 * contention (S3-T11 gap 2 — `RefreshDatabase`'s per-test transaction makes
 * a genuinely separate database session unable to see these uncommitted
 * rows; that limit is structural and is ledgered, not fixed here).
 */
final class OutboxBookingDraftPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_booking_mutation_produces_an_event_the_publisher_claims_and_dispatches(): void
    {
        Queue::fake();

        $draft = (new StartBookingDraft)(userId: null);

        $event = OutboxEvent::query()->where('event_name', 'booking.draft_started.v1')->sole();
        $this->assertNull($event->dispatched_at, 'A freshly recorded event must start undispatched.');

        $this->publishPendingEvents();

        Queue::assertPushed(PublishOutboxEventJob::class);

        $this->assertNotNull(
            $event->fresh()->dispatched_at,
            'The publisher must mark a claimed event dispatched.'
        );
        $this->assertSame((string) $draft->id, $event->aggregate_id);
    }

    public function test_several_steps_of_one_journey_each_publish_independently(): void
    {
        Queue::fake();

        $draft = (new StartBookingDraft)(userId: null);
        $saved = (new SaveBookingDraftStep)($draft, 1, ['city_code' => 'JAKARTA'], 'step-1-key');

        $this->assertSame(
            2,
            OutboxEvent::query()->whereNull('dispatched_at')->count(),
            'One draft-started plus one step-saved event should be pending.'
        );

        $this->publishPendingEvents();

        Queue::assertPushed(PublishOutboxEventJob::class, 2);

        $this->assertSame(
            0,
            OutboxEvent::query()->whereNull('dispatched_at')->count(),
            'Every pending event must have been claimed and dispatched.'
        );
        // `booking_drafts.version` DEFAULTS TO 1, so the first accepted
        // save leaves it at 2. See Task 2's corrected implementer note.
        $this->assertSame(2, $saved->version);
    }

    public function test_booking_draft_events_route_to_the_default_queue(): void
    {
        // Deliberate: both names are unmapped in `OutboxQueueRouter::ROUTES`,
        // and that class's doc block says an unmapped event correctly falls
        // back to `default` rather than being guessed at. This pins that
        // decision so a future edit to ROUTES cannot change it silently.
        $this->assertSame(
            OutboxQueueName::Default,
            OutboxQueueRouter::routeFor('booking.draft_started.v1')
        );
        $this->assertSame(
            OutboxQueueName::Default,
            OutboxQueueRouter::routeFor('booking.draft_step_saved.v1')
        );
    }

    private function publishPendingEvents(): int
    {
        return (new OutboxPublisher)->publishBatch();
    }
}
```

Add `use App\Platform\Outbox\OutboxPublisher;` to the imports.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=OutboxBookingDraftPublicationTest`
Expected on this host: **BLOCKED / NOT TESTED** — this test in particular *cannot* run here even with `vendor/` present, because the claim loop requires real PostgreSQL. CI is the only place its result is meaningful. Syntax-check with `php -l`.

- [ ] **Step 3: No implementation needed**

Tasks 1 and 2 already wrote the production code. If this test needs production changes beyond filling in the publisher call, stop and report it — that means Task 1 or Task 2's row shape is wrong, which is a finding, not a fix to smuggle in here.

- [ ] **Step 4: Verify**

Run: `php -l tests/Feature/Outbox/OutboxBookingDraftPublicationTest.php`
Run: `bash ci/verify-docs.sh`

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Outbox/OutboxBookingDraftPublicationTest.php
git commit -m "test(outbox): prove AC1 end-to-end against a real domain mutation"
```

---

## Task 4: Transaction-atomicity regression proof

**Files:**
- Modify: `tests/Feature/Domain/Booking/Actions/BookingDraftOutboxTest.php` (extend)

**Interfaces:**
- Consumes: Tasks 1 and 2's production code, unchanged.
- Produces: nothing other tasks consume.

This mirrors `tests/Feature/FeatureGate/GateActivationRecorderTest.php`'s established shape — success plus **both** rollback directions — which is the evidentiary bar the existing producer was held to. AC1's own words: "A committed mutation with no outbox row is a defect." The inverse (an outbox row with no committed mutation) is equally a defect, and both directions need pinning.

**Implementer instruction — read `tests/Feature/FeatureGate/GateActivationRecorderTest.php` first** and follow its failure-injection idiom. The mechanism below is the required *behaviour*; if that file already establishes a cleaner injection idiom for this codebase, use it.

- [ ] **Step 1: Write the failing tests**

```php
    public function test_a_failing_outbox_write_rolls_back_the_draft_itself(): void
    {
        // A denylisted key makes `PayloadClassification::assertSafe()` throw
        // from inside `Outbox::record()` — the one failure mode reachable
        // without mocking, and a real one.
        $before = BookingDraft::query()->count();

        \Illuminate\Support\Facades\Event::listen(
            'eloquent.creating: '.OutboxEvent::class,
            static fn () => throw new \RuntimeException('simulated outbox failure')
        );

        try {
            (new StartBookingDraft)(userId: null);
            $this->fail('Expected the simulated outbox failure to propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($before, BookingDraft::query()->count(), 'The draft must not survive a failed outbox write.');
        $this->assertSame(0, OutboxEvent::query()->count());
    }

    public function test_a_failing_draft_write_leaves_no_outbox_event_behind(): void
    {
        \Illuminate\Support\Facades\Event::listen(
            'eloquent.creating: '.BookingDraft::class,
            static fn () => throw new \RuntimeException('simulated draft failure')
        );

        try {
            (new StartBookingDraft)(userId: null);
            $this->fail('Expected the simulated draft failure to propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, OutboxEvent::query()->count());
        $this->assertSame(0, BookingDraft::query()->count());
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter=BookingDraftOutboxTest`
Expected on this host: **BLOCKED / NOT TESTED**. Syntax-check only.

- [ ] **Step 3: No implementation needed**

The transaction already exists. If either test fails in CI, that is a **real defect in Task 1's placement** — the `Outbox::record()` call escaped the transaction. Fix the placement, do not weaken the test.

- [ ] **Step 4: Verify**

Run: `php -l tests/Feature/Domain/Booking/Actions/BookingDraftOutboxTest.php`
Run: `bash ci/verify-docs.sh`

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Domain/Booking/Actions/BookingDraftOutboxTest.php
git commit -m "test(booking): pin outbox/draft transaction atomicity in both directions"
```

---

## Task 5: Review slices, fix wave, and documentation correction

This task is not a code change; it is the retrofit's actual review machinery plus the documentation-correction step, and it runs after Tasks 1–4 are committed.

### 5a. Task-scoped review slices (dispatched concurrently)

Four read-only reviewers, one per slice, each given only its own boundary. Each returns findings triaged **Critical / Important / Minor** with file:line citations.

- [ ] **Slice A — domain-mutation.** `StartBookingDraft.php`, `SaveBookingDraftStep.php`. Is each `Outbox::record()` genuinely inside the existing transaction? Is the event name/classification/idempotency key defensible? Does the payload honour AC2's references-only rule? Does anything in the change alter the Actions' pre-existing behaviour (validation order, version bumping, replay early-return)?
- [ ] **Slice B — schema/contracts.** `event-catalog.md` reconciliation, `outbox-event-contract.md` envelope conformance, `PayloadClassification` denylist adequacy for these two payloads, `OutboxQueueRouter` fallback correctness. Confirm the N-12-pattern disclosure is genuinely equivalent to the precedent and not a weaker version of it.
- [ ] **Slice C — tests.** Both new test files plus the six pre-existing `tests/Feature/Outbox/**` files. Are the new assertions behavioural rather than tautological? Is the end-to-end proof genuinely end-to-end, or does it secretly still lean on a fixture? Are there vacuous assertions (the pilot retrofit caught one; look for the same shape)?
- [ ] **Slice D — whole-module.** `Outbox.php`, `OutboxPublisher.php`, `PublishOutboxEventJob.php`, `OutboxEvent.php` doc blocks — which claims are now stale given a real producer exists? Specifically: `Outbox.php:44-52` says "no real consumer existed yet — this is that consumer" about correlation, and its own hedge about what is not proven end-to-end may now be partly out of date.

### 5b. Bounded fix wave

- [ ] Triage all four slices' findings into one list. Critical and Important get one bounded fix wave with a real regression test each (`AGENTS.md` §Testing). Minor is ledgered and parked unless trivial.
- [ ] Maximum 5 rounds. **Escalate to the team lead at round 4** rather than continuing — that is this lane's supervision protocol, not a suggestion.
- [ ] Escalate instead of deciding if any finding requires touching security, authorization, financial, privacy, or migration/destructive-schema surface. The booking-draft producer choice is pre-cleared; nothing else is.
- [ ] Escalate if a finding contradicts this plan's own text — particularly the two disclosed gaps above, which reviewers may mistake for defects.

### 5c. Scoped re-review

- [ ] One reviewer re-checks only the fix wave's diff. Verify each claimed fix is real and its regression test actually fails without the fix (the pilot retrofit found a vacuous fix at exactly this step).

### 5d. Documentation correction — every self-flagged gap gets an explicit disposition

- [ ] **`.kiro/specs/platform-outbox/tasks.md`:** delete the false "## NOT TESTED / Nothing here is implemented" section and replace it with an honest per-AC status. Check the boxes Batch 3.4 genuinely completed (envelope/table, write helper, `SKIP LOCKED` claim, queue routing, bounded backoff, payload denylist, the recovery test) and leave genuinely-unbuilt ones unchecked (Horizon supervisors, on-demand worker isolation, bounded replay, the 10k-import test, the graceful-termination test). State plainly that `event-catalog.md` reconciliation is now **partially** done and what remains.
- [ ] **`.kiro/specs/platform-outbox/design.md`:** record the dispositions the review surfaces. Do not restate acceptance criteria — `requirements.md` owns those.
- [ ] **`docs/planning/sprint-plan.md` S3-T11 row (line 555):** **append-correct, never rewrite.** Follow the exact convention on the S4-T6 (line 628) and S4-T2 (line 624) rows: leave every word of the original in place and append a **`Correction, 09 Aug 2026 (retrofit):`** block. It must state what shipped, that gap 1 (AC1 against a real domain mutation) is now closed and *how*, that gaps 2–4 remain open with named reasons, the two disclosed gaps from this plan's scope decision, the PR number, and the CI run ID.
- [ ] **`docs/planning/sprint-plan.md` Appendix A findings table:** add **finding N-17** — no catalogued event name exists for a booking-draft lifecycle event; `booking.draft_submitted.v2` is a Step 9 submission event and Step 9 is unbuilt; two provisional names are now in production code; whoever owns `docs/contracts/event-catalog.md` next should either add the two rows or rule that draft-lifecycle events do not belong in the catalogue. Follow N-12's and N-13's own shape (the finding, the resolution taken, what still needs to happen, severity, affected paths).
- [ ] **`docs/planning/retrofit-backlog.md` §1 item 6:** change status from "Not started" to "✅ **Done** — 09 Aug 2026", note which producers were wired, link the PR and CI run. Match items 1–3's row formatting exactly.
- [ ] **`docs/planning/retrofit-backlog.md` §2:** add a new `### Outbox, retrofitted 09 Aug 2026` entry. **Read the existing `Faq` §2 entry first and copy its structure** — an intro paragraph naming what made this module different, then a `| Gap / finding | Disposition | Owner / reason |` table, then a "Full evidence trail:" line. Every gap gets closed-with-evidence or ledgered-with-a-named-reason; no silent carry-forward.
- [ ] Run `bash ci/verify-docs.sh` after the documentation edits and confirm ALL DOC GATES PASS.
- [ ] Commit the documentation correction as its own commit.

---

## Task 6: Finish the branch

- [ ] Use `superpowers:finishing-a-development-branch`. Push `retrofit-outbox` and open a PR against `docs/design-system-and-planning`.
- [ ] The PR body states plainly: which producers were wired and why, the two disclosed gaps, which of S3-T11's four gaps this closes (one) and which it does not (three), and the full finding counts with dispositions.
- [ ] **Do not merge.** Report the PR number, the producer decision, the findings summary, and CI status back to the team lead.

---

## Verification

The evidence bar every prior retrofit in this program has met:

- [ ] Plan doc committed **before** any implementation started.
- [ ] Populated `.superpowers/sdd/retrofit-outbox/` ledger (task briefs, task reports, whole-module review, fix-wave commits, re-review).
- [ ] Every self-flagged gap has an explicit disposition — closed with a real regression test, or ledgered with a named reason and owner.
- [ ] `sprint-plan.md` and `retrofit-backlog.md` append-corrected with original text untouched.
- [ ] CI green on PostgreSQL 18. Until CI reports, every test result in this branch is `NOT TESTED`, never `PASS` — `AGENTS.md` §Infrastructure-agent execution.
- [ ] PR opened, not merged.
