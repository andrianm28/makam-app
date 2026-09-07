# Batch M8a — Observability & PII-leak audit remediation (OBS-01, 02, 03, 04, 07, 08)

Phase 3 of `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`. Six
findings, two of which (OBS-02, OBS-07) are genuine PII/security leaks into
the error tracker and are treated with priority review urgency.

## Findings and fixes

### OBS-01 — no database-level append-only enforcement on `audit_events`/`document_access_events`

- New migration `database/migrations/2026_09_07_100000_enforce_audit_events_append_only.php`,
  mirroring `2026_08_11_100000_enforce_journal_append_only.php` exactly: a
  `reject_audit_history_mutation()` plpgsql trigger function (`ERRCODE 42501`),
  attached `BEFORE UPDATE OR DELETE ON audit_events` and the same on
  `document_access_events`, guarded on `DB::connection()->getDriverName() !== 'pgsql'`
  so SQLite unit tests are unaffected.
- Updated `app/Platform/Audit/Models/AuditEvent.php`'s class doc block to
  describe the trigger as the real, closing control, superseding the
  previously "PARTIALLY satisfied" framing.
- `tests/Feature/Audit/AuditEventAppendOnlyTest.php` and
  `tests/Feature/DocumentVault/DocumentAccessEventAppendOnlyTest.php` extended
  with DB-level bypass tests (query-builder mass update, raw `DB::table()`
  update/delete) that assert REFUSAL on PostgreSQL and the documented-open gap
  on SQLite — same pattern as `tests/Feature/FinancialLedger/JournalAppendOnlyTest.php`.

### OBS-02 (security) — Sentry signed-URL scrubber matched a shape the app never generates

- The old pattern targeted `https://.../vault/...?...signature=...`, which
  does not exist in `routes/web.php`. The real route
  (`app/Platform/DocumentVault/Actions/IssueSignedUrl.php:140`,
  `routes/web.php:699`) is
  `/internal/documents/{uuid}/download/{64-hex-token}` — a path-segment
  token, no query string.
- `app/Platform/Observability/SentryEventScrubber.php` fixed to match the
  real shape, redacting only the token segment (document id stays visible for
  triage), plus a generic `signature=`/`expires=` query-parameter scrub as
  defense-in-depth for any future `temporarySignedRoute()` use.
- `tests/Unit/Platform/Observability/SentryEventScrubberTest.php` rewritten
  to mint a REAL grant via `IssueSignedUrl::issue()`/`temporaryUrl()` rather
  than a hand-typed fixture shaped to match the new regex.

### OBS-03 — `Audit::record()`'s `$correlation_id` is optional and 72/149 call sites omit it

- `app/Platform/Audit/Audit.php`: `$correlationId ??= app(CorrelationContext::class)->current()?->value;`
  inside `record()`, mirroring `Outbox::record()`'s existing pattern. Explicit
  argument still overrides.
- New tests in `tests/Feature/Audit/AuditRecordTest.php` proving the default,
  the override, and the "neither present" null case.

### OBS-04 — correlation id dropped at 8 of 9 queue hops

- `PublishOutboxEventJob` and `DispatchNotification::consumeOutboxEvent()`
  (which dispatches `ConsumeOutboxNotificationJob`'s work) now bind the
  row's/envelope's own `trace_id` via `CorrelationContext::set()` before any
  further work — not ambient capture, since the row may be processed long
  after and by a different worker than whatever request originally wrote it.
- `ProcessProviderEventJob`, `CleanupPromotedDocumentStorageJob`,
  `ScanDocumentJob`, `SendNotificationChannelJob`, `RetryFailedDeliveryJob`,
  `ReconcileDocumentStorageCleanupJob` all adopt `CarriesCorrelationId`
  (capture in constructor, restore as `handle()`'s first line) —
  `ReconcileStatementJob` already had it. All 9 `ShouldQueue` classes in the
  codebase are now covered.
- New `tests/Feature/Correlation/QueueJobCorrelationPropagationTest.php`
  proves the envelope-trace-id bind for the two jobs that needed it.

### OBS-07 (PII leak) — `QueryException` interpolates every SQL binding into its message

- `App\Platform\Observability\SentryEventScrubber::redactQueryExceptionMessages()`
  is the single fix point: wired as an early `reportable()` callback in
  `bootstrap/app.php`, registered BEFORE `Sentry\Laravel\Integration::handles()`.
  It mutates (via `ReflectionProperty`) every `QueryException` in the
  exception's `getPrevious()` chain, in place, replacing the message with a
  redacted summary built from the placeholder SQL (`getSql()`, never
  interpolated) and the binding COUNT — never the raw driver text, since
  PostgreSQL's own PDO driver can independently echo a real value into a
  unique-constraint `DETAIL` line. Because both the default log write and
  Sentry's capture read `->getMessage()` from the SAME (now-mutated) object,
  one fix point covers both sinks.
- `scrub()` also gained a defense-in-depth regex fallback for the one path
  the `reportable()` hook cannot reach (a direct `\Sentry\captureException()`
  call bypassing `report()` — nothing in this codebase does that today).
- New `tests/Feature/Observability/QueryExceptionRedactionTest.php` triggers
  a REAL Postgres unique-constraint violation with a distinctive fake
  "deceased name" value and proves it does not reach the exception's own
  message, the log sink (`MessageLogged` event), or the Sentry payload
  (`SentryEventScrubber::scrub()` on a `Sentry\Event` built the same way
  `sentry-laravel` builds one).

### OBS-08 — nothing enforces `APP_DEBUG=false` on the publicly reachable dev host

- `ci/verify-infra.sh` GATE I12 added (~line 300): reads
  `php artisan about --json`'s `environment.debug_mode` from the running
  `dev-web`/`stg-web` containers and fails when debug is on for a publicly
  reachable vhost. **NOT TESTED end-to-end** — this script only runs on the
  deployment host per `CLAUDE.md`; syntax-checked (`bash -n`) only. A human
  must run it for real on `makam-nonprod` to confirm container names match
  and the gate behaves as intended.
- `docs/operations/ai-agent-dev-stg-setup-prompt.md`'s development baseline
  changed from `APP_DEBUG=true` to `APP_DEBUG=false`, with a dated note
  explaining why the old baseline was wrong once ADR-0031 made the host
  public.
- `docs/adr/0031-make-dev-environment-public.md`'s Negative-consequences
  bullet strengthened from "should be reviewed" to a hard requirement,
  referencing the new gate.

## Verification

- `bash ci/verify-docs.sh` — PASS (run directly on host, no container).
- `vendor/bin/pint --test` — PASS, 1746 files (via the pinned CI-parity
  Docker image; one pre-existing-style auto-fix applied and re-verified
  clean).
- `vendor/bin/phpstan analyse` — PASS, no errors (same image, 1G memory
  limit).
- `vendor/bin/phpunit` against a disposable `postgres:18`/`redis:8.2-alpine`
  pair (`m8a-pg`/`m8a-redis`, removed after the run):
  - All new/updated tests for this batch (59 tests, 113 assertions): PASS.
  - Full regression sweep of every touched module
    (`tests/Feature/Outbox`, `tests/Feature/Notification`,
    `tests/Feature/DocumentVault`, `tests/Feature/Payment`,
    `tests/Feature/FinancialLedger`): 673 tests, 2986 assertions, PASS.
  - Full repo-wide suite: **NOT RUN** (time-boxed to the touched-module
    regression sweep above, which is the CLAUDE.md/AGENTS.md-required real
    PostgreSQL verification for this diff).
- `ci/verify-infra.sh` GATE I12: **NOT TESTED** — cannot execute on this
  host; syntax-checked only (`bash -n ci/verify-infra.sh` — OK). Flagged for
  human verification on the deployment host in the PR description.

## Priority human review flags

- **OBS-02** and **OBS-07** are real PII/security leaks into the error
  tracker (a live signed-download token; personal data such as deceased
  names via SQL bindings/driver DETAIL text). Treat as priority review.
- **OBS-08**'s `ci/verify-infra.sh` gate needs a human to run it for real on
  the deployment host and confirm the `dev-web`/`stg-web` container names
  match the actual `compose.yml` service keys.
