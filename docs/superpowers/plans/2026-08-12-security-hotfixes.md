# Platform Security Hotfixes — Audit Reason + Notification Write Guard

> **Retroactive plan.** This document was written after the work landed, from
> the ad hoc brief the lane was dispatched with. `AGENTS.md` §Development
> methodology requires a committed plan at
> `docs/superpowers/plans/<date>-<slug>.md` *before* implementation starts; that
> did not happen here, and the whole-branch review raised it (IMPORTANT-3). The
> task boundaries below are the ones the work actually followed — they are
> reconstructed, not aspirational, and each records the commit that closed it.

**Goal:** Close two cross-cutting security findings surfaced by lane L5's
whole-branch review, both in already-merged, already-shipped code that every
sensitive action or every notification-delivery write depends on.

**Source of both findings:**
[`2026-08-11-platform-identity-seam.md`](2026-08-11-platform-identity-seam.md)
§"Cross-cutting finding for the merge sign-off bundle" and §"Second cross-cutting
finding". Both are recorded closed there, with the corrections noted below.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL 18 (CI/production), SQLite
in-memory (default local test connection), PHPUnit.

## Why the two fixes share one branch and one PR

They are independent — zero file, symbol, or SQL overlap, confirmed by the
whole-branch review — and `AGENTS.md:152` asks for one PR per unit of work. They
are nonetheless bundled, as an explicit coordinator ruling:

- Both surfaced from the same pass (L5's whole-branch review) and are recorded in
  the same document, so they close together or the record stays half-stale.
- Both are small, separately committed, and were independently reviewed and
  approved before the whole-branch review ran, so the review granularity the rule
  protects was preserved by commit, not by PR.
- Splitting at the point the deviation was noticed — after three review rounds —
  would have cost more than it returned.

The cost accepted: reverting either fix alone is a partial revert, and human
sign-off is given over the pair rather than per fix.

## Global Constraints

- Security-adjacent changes to shared infrastructure. Full independent review per
  fix plus a whole-branch review; human sign-off before merge, no self-merge
  (`AGENTS.md` §Infrastructure-agent execution).
- Never report `PASS` for a check that was not executed. Use `BLOCKED` or
  `NOT TESTED` explicitly.
- Do not run `composer install` or `npm run build` on this host (`CLAUDE.md`
  §Scope note).
- Do not touch `MetadataAllowlist`, `SensitiveActions::ACTIONS`, or any consumer
  of either fixed class beyond what the fix needs.
- No PostgreSQL on this host; PostgreSQL verification is not required (neither
  fix touches schema or migrations) but the gap is declared, not papered over.

## Fix 1 — `Audit::record()` accepts a Unicode-whitespace-only reason

`app/Platform/Audit/Audit.php` guarded the mandatory reason on sensitive actions
with `trim($reason) === ''`. PHP's `trim()` strips only ASCII whitespace, so a
reason of a single U+00A0, U+3000, U+200B, BOM, figure space, soft hyphen or word
joiner passed as non-blank, and the sensitive action proceeded with a
justification invisible to anyone reviewing the trail. Every action on
`SensitiveActions::ACTIONS` was reachable this way — `PAYMENT_REFUND`,
`PAYMENT_CHARGEBACK`, `VENDOR_PAYOUT`, `JOURNAL_REVERSAL`,
`RECONCILIATION_EXCEPTION_RESOLVED`, `MFA_RESET`, `DOCUMENT_DELETE`,
`PLOT_OVERRIDE`, plus L5's `ROLE_GRANT`/`ROLE_REVOKE`/`SCOPE_GRANT`/`SCOPE_REVOKE`
— all already merged.

**Design decisions taken (why this needed a plan doc at all):**

1. Reuse L5's already-reviewed `/^[\p{Z}\p{C}\s]*$/u` pattern rather than invent
   one. `\p{Z}` covers Unicode separators, `\p{C}` covers control and format
   characters.
2. Fail **closed** on malformed input: test `preg_match(...) !== 0`, not `=== 1`.
   `preg_match` returns `false` on invalid UTF-8 under the `u` modifier, and
   `false === 1` is `false` — so the `=== 1` form treats an unparseable reason as
   non-blank and lets it through. This was the polarity bug in the first
   implementation.
3. Accept, and document, that `\p{Lo}` residuals such as Hangul filler are not
   enumerated. Rejecting them would risk over-rejecting legitimate script.
4. Leave `RequiresAuditReason.php`'s console-layer guard in place. The root check
   supersedes it as authoritative; the trait still owns the operator-facing error
   message. Defence in depth.

- [x] **Task 1.1** — Unicode-aware blank check at the shared root, with
      regression tests asserting NBSP-only is rejected exactly as empty is, and
      that real Indonesian prose with em-dashes and accents is still accepted.
      Mutation-verified: reverted to `trim()`, watched the new tests fail,
      restored, watched them pass. → `1cb124c`
- [x] **Task 1.2** — Fail closed when the reason is not valid UTF-8
      (decision 2 above; `1cb124c` shipped the fail-open form). → `6dba6c1`
- [x] **Task 1.3** — Make the command-layer tests pin the layer they name. The
      root guard now also rejects these inputs, so a test asserting only "the
      command failed" would pass with the trait deleted; the tests assert the
      command's own error text instead. → `b26a50c`

## Fix 2 — `NotificationDeliveryWriteGuard` fails open under `spl_object_id` reuse

`register()` deduplicated hook attachment with
`isset(self::$registeredConnections[spl_object_id($connection)])`. PHP reuses an
object id once the original is collected, so a fresh connection could be handed a
dead one's id, look already-registered, and receive no `beforeExecuting()` hook at
all — every write to `notification_deliveries` on it silently unguarded. The
guard failed **open**, non-deterministically, under GC timing. It was observed
once during L5's review, when added test volume shifted allocation timing.

**Design decision:** key a `WeakMap` on the connection object itself rather than
on the connection *name* (the other candidate the finding named). True object
identity cannot collide, and the entry drops when the connection is collected,
which is the actual question being asked — "has THIS live connection been
hooked?" Initialised on first use, since PHP's "new in initializers" does not
extend to property defaults.

- [x] **Task 2.1** — Re-key to `WeakMap`, with a test proving the mechanism is
      immune to id reuse: purge the connection, force collection, confirm the
      replacement is hooked even when PHP hands it the recycled id. → `09d716d`
- [x] **Task 2.2** — Correct the causal story and the failure diagnostic. → `456ffcd`

### Correction carried by Task 2.2

`09d716d`'s commit message and the original finding both attributed the
connection churn to Horizon queue workers. **That is wrong.**
`Illuminate\Queue\Worker` never purges the connection, and
`DatabaseManager::reconnect()` swaps the PDO on the same `Connection` object, so
a plain queue worker never reaches the collision. The real trigger is repeated
application bootstraps in one process: the test suite, and Octane, which is not
installed here. Commit messages are immutable, so the correction lives in the
docblock, in the closed finding record, and in the PR body.

## Known gap — deliberately out of scope

`register()` runs once per bootstrap from `NotificationServiceProvider::boot()`.
Nothing re-attaches the hook if something purges the connection mid-process, and
that is true regardless of how the map is keyed — the `WeakMap` makes
re-registration *correct* whenever it happens, it does not make it *happen*.

This is **pre-existing and not introduced by this branch.** Closing it needs a
listener on `Illuminate\Database\Events\ConnectionEstablished`, for which this
keying is the right substrate: repeated registration is now safe and
self-cleaning. Tracked as a follow-up, not attempted here — it is a behavioural
change to a security guard's registration lifecycle and deserves its own review
and regression pass.

## Verification

- [x] Fix 1 mutation-tested: revert to `trim()` → new tests fail; restore → pass.
- [x] Fix 2 keying proven immune to id reuse. The original race's exact
      non-determinism is **NOT TESTED** — it cannot be forced deterministically,
      which is the nature of the bug.
- [x] Full local suite green at baseline across four runs (one default ordering,
      three random seeds).
- [x] `ci/verify-docs.sh` clean; Pint and PHPStan clean.
- [ ] **NOT TESTED on PostgreSQL.** Every local run was on SQLite. The Fix 2
      test is the repository's only test that names a driver
      (`sqlite_guard_probe`) rather than inheriting the default, and its
      precondition — that PHP hands the replacement connection the recycled
      `spl_object_id` — has only ever been exercised with a SQLite default
      connection alive in the manager. It fails loudly rather than silently if
      that does not hold, so the worst case is a red CI run, not a false green.
      The branch's first CI run is part of the verification, not a formality.
