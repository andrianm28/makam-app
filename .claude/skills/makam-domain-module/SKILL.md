---
name: makam-domain-module
description: Conventions for anything under app/Domain/** in this repository — the closed-list-class rule and when a native enum is instead correct, model shape (final, fillable, casts(), booted() validation, relation generics), the Action shape (readonly, transaction, lockForUpdate, one Audit::record), what Audit and Outbox reject at write time, and the doc-block culture. Use when adding or changing a domain model, Action, closed list, or Query class, or when consuming a platform foundation from app/Platform.
---

# Domain modules

`app/Domain/<Module>/` holds `Models/`, `Actions/`, closed-list classes, `*AuditActions`, and one `*Query` read entry point. Nothing here is written down in `docs/` — it lives in the code's own doc blocks. Read `app/Domain/Faq/**` end to end before adding a module; it is the most complete example.

## The closed-list rule — get this right first

A closed list that **backs a database column** is a `final class` of `public const string` members plus a `KNOWN_*` array and `isKnown()`/`assertKnown()`. **26 such classes exist** (20 in `app/Domain`, 6 in `app/Platform` — MFA, Reauthentication, Scopes). The column is `$table->string(N)` with **no DB constraint**; validation runs in the model's `booted()` hook on `saving`. `FaqCategoryCode` and `ServiceCode` are the reference shape.

The reasoning, quoted from `ScopeEntityType` (the origin, quoting the batch brief that set the convention): *"a string column with app-level validation against a known-types list, not a Postgres enum type that requires a migration to extend."* Adding a value later is a one-line change in one class, not a schema migration. `FaqCategoryCode` adds the product framing: extending the six FAQ categories is a change to `docs/product/faq-catalog.md` first, not a routine code change.

Native PHP `enum` is used **only** for fixed protocol/contract values in `app/Platform` — 9 exist: the five FeatureGate `Modes\*`, plus `AuditOutcome`, `AuditSource`, `OutboxClassification`, `OutboxQueueName`. Each cites the contract document that fixes its cases. Two of them (`AuditOutcome`, `OutboxClassification`) do back a DB column, but with a real Postgres `CHECK` behind them — see `makam-migration`. **Do not take that as licence.** For a new DB-backed domain list, use the `final class` form.

## Models

28 models under `app/Domain/**/Models/` and `app/Platform/**/Models/`. All 28 are `final class` with `declare(strict_types=1)`. (`app/Models/User.php` is neither — it is the untouched Laravel scaffold, not the convention.)

- Always `protected $fillable`. **`$guarded` appears nowhere in `app/`** — do not introduce it.
- Casts through `protected function casts(): array` with an `@return array<string, string>` annotation. The annotation is load-bearing: see `phpstan.neon`'s comment on Larastan's `ModelCastHelper`, which is why `parseModelCastsMethod: true` is set there.
- `public $timestamps = false` on the 8 append-only / event-log tables, each with a doc block giving the reason (`AuditEvent`, `OutboxEvent`, `MenuInteractionEvent`, `ReauthenticationEvent`, `MfaChallenge`, `FaqArticleVersion`, `PriceVersion`, `CemeteryCapabilityProfile`). An `updated_at` on a row that is never revised is a lie about the data.
- `booted()` calls `assertKnown()` for every closed-list column on `saving` — `Cemetery` validates three at once.
- Query scopes are `scopeXxx(Builder $query): void`; static `findByCode()` for code lookups.
- Every relation carries generics — `@return HasMany<FaqArticleVersion, $this>`, `@return BelongsTo<FaqCategory, $this>`. Larastan needs them. It runs at **level 2** (`phpstan.neon`, kept deliberately low) and CI marks the step `continue-on-error: true` pending a baseline — so a Larastan regression will **not** fail your build. Do not treat green CI as proof the annotations are right.

Visibility guarantees live in a scope, and the scope's doc block says so. `FaqArticle::scopePublished()` is a *local* scope on purpose (admin surfaces must see drafts) — read its class-level AC6 block before writing any query against it. Public reads go through the module's `*Query` class, never the model directly; see `makam-livewire-page`.

## Actions

10 classes under `app/Domain/**/Actions/`, all `final readonly class` with a single `__invoke()`. Nine are write Actions and share one shape:

1. Body wrapped in `DB::transaction()`.
2. `lockForUpdate()` on the row **before** reading state to branch on it — `$article = FaqArticle::query()->lockForUpdate()->findOrFail($article->id)` re-reads under the lock rather than trusting the passed-in instance.
3. Exactly one `Audit::record()` at the end, inside the same transaction.
4. Action name from a `*AuditActions` constant class (`FaqAuditActions`, `ServiceCatalogAuditActions`), never a raw string.
5. Plain `InvalidArgumentException` for caller error; a domain-specific exception only when the rule deserves a name (`PublishedServicePackageVersionIsImmutableException`).

The tenth, `ResolveCemeteryCapabilityProfile`, is read-only — no transaction, no audit. Its doc block explains why it returns an unsaved `Model::make()` fallback rather than writing a phantom row. If your Action does not write, say so there too.

**Zero Domain Actions call `Outbox::record()`.** The only production caller anywhere is `App\Platform\FeatureGate\GateActivationRecorder`. Read it for the canonical paired Audit+Outbox write: both inside one transaction, Outbox first, with the classification choice and the idempotency key each justified in a comment.

## What Audit and Outbox reject at write time

`Audit::record()` throws before it writes:

- **`SensitiveActions::ACTIONS`** — a closed, hand-reviewed list. Today: `DITOLAK`, `PLOT_OVERRIDE`, `TARIFF_SOURCE_CHANGE`, `GATE_CHANGE`, `PAYMENT_MANUAL_VERIFICATION`, `CERTIFICATE_REVOKE`, `VENDOR_PAYOUT`, `MFA_RESET`. If your action is on it, a non-blank `$reason` is mandatory or you get `AuditReasonRequiredException`. Its doc block rejects magic-string conventions ("any action ending in `_REJECTED`") deliberately — extend the list, never infer sensitivity from a name at runtime.
- **`MetadataAllowlist::ALLOWED_KEYS`** — a closed allowlist: `reference_number`, `previous_state`, `new_state`, `note`, `method`, `recovery_codes_remaining`. Any other key throws `AuditMetadataKeyNotAllowedException`. The review step needed to add a key *is* the control keeping a KTP number or bank detail out.

`Outbox` makes the **opposite** choice: `PayloadClassification::DENYLISTED_KEYS`, checked recursively. Its doc block gives the reason, and it is worth internalising — `audit_events.metadata` is a small shape the platform owns end to end, so an allowlist is enumerable. `outbox_events.payload` carries per-event `data` defined by 23+ producers the outbox module has no authority over; an allowlist there would either restate every event schema (which `platform-outbox` AC3 forbids) or degenerate into allowing everything. Note what the denylist does *not* do: it checks key names, not values.

## Platform foundations are consumed, never redefined

`app/Platform/README.md`: *"a feature module consumes a platform foundation and must never redefine one."* Its tier table — Tier 0 `IdentityAccess`, `FeatureGate`, `Audit`, `Outbox` (Sprint 3); Tier 2 `Notification`, `DocumentVault` (Sprint 6); Tier 3 `Payment`, `FinancialLedger` (Sprints 8–9) — with *"Nothing ships before Tier 0."* If you need something a Tier 2/3 foundation would provide, report the dependency; do not build a local version of it.

## Doc-block culture

This is not decoration. Every class opens with the spec and AC it implements, cites AC numbers inline next to the implementing code, and states what is deliberately out of scope (`FaqArticle`'s "no draft-buffer architecture", `ResolveCemeteryCapabilityProfile`'s deferred write-side Action).

When two authority documents conflict, **resolve it explicitly and show the reasoning**. `ServiceCode` picks the canonical `service-catalog.md` over its own commissioning brief's miscount and says why. `2026_07_26_140000_create_outbox_events_table.php` does the same for a column-name conflict.

And the rule this repo actually lives by: **a superseded statement is corrected by appending, never by deleting.** The worked example is findings **N-10** and **N-11** in `docs/planning/sprint-plan.md` — N-10 told future readers to expect a `correlation_id` column; the real column is `trace_id`. N-10's row was not edited to hide the error. A dated "**Correction (finding N-11, Batch 3.4, 26 Jul 2026)**" addendum was appended to it, and N-11 records the full reconciliation. Read both rows before writing a correction of your own.
