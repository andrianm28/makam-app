# ADR-0038: `tasks.md` Owns Per-Spec Progress; There Is No External Issue Tracker

## Status

Accepted.

## Context

`AGENTS.md` has contradicted itself about who owns progress for as long as both statements have existed.

§Documentation says:

> `tasks.md` is planning only; issue tracker owns progress.

§Development methodology says the opposite:

> Kiro specs (`.kiro/specs/*/{requirements,design,tasks}.md`) remain the "what to build" authority — acceptance criteria, traceability, **durable per-spec progress**.

The same section already admits the conflict rather than resolving it:

> This does not resolve the open "which issue tracker" decision — `tasks.md` still says the issue tracker owns progress and none is named; that stays open.

**No issue tracker has ever been named.** The repository has run its entire life — 28 Kiro specs, ~55k lines of application code, 3,723 tests — without one. "The issue tracker owns progress" therefore does not describe a system that exists; it describes an absence, and it has been used, in effect, as permission for nothing to own progress.

The consequences are measurable. Across `.kiro/specs/`, **256 task checkboxes are open against 136 done**, and **13 specs show zero completed tasks while their code is fully built**:

| Spec | done/open | Shipped code |
|---|---|---|
| `platform-identity-and-access` | 0/11 | `app/Platform/IdentityAccess` — 39 PHP files |
| `memorial-and-qr` | 0/13 | `app/Domain/Memorial` — 28 files |
| `platform-feature-gate` | 0/12 | `app/Platform/FeatureGate` — 21 files |
| `pre-need-contracting` | 0/17 | `app/Domain/PreNeed` — 21 files |
| `certificates-and-agreements` | 0/13 | `app/Domain/AgreementCertificate` — 20 files |

Only one spec (`platform-notifications`) is fully checked off. The unchecked boxes do not mean "never built" — they mean the ledger was abandoned once execution moved to `docs/superpowers/plans/`, and nothing has reconciled the two since.

An artifact that is wrong in a *known, systematic* direction is worse than no artifact: it is read by both humans and agents as evidence, and it silently misleads them. The reconnaissance pass that preceded this ADR called resolving the contradiction the single highest-leverage decision available in the documentation cleanup, and that assessment is the reason this ADR exists.

A third ledger now also exists: [`docs/remediation/findings.yml`](../remediation/findings.yml), created by W0 of the same programme to hold the status of the 343 audit findings. Its scope must be stated here too, or the same ambiguity reappears with a new file.

## Decision

1. **There is no external issue tracker, and the question is closed.** The repository does not adopt one. Any future adoption supersedes this ADR rather than reopening an "open question".

2. **`.kiro/specs/*/tasks.md` is the durable, authoritative per-spec progress ledger.** Its checkboxes are the answer to "is this acceptance criterion built". `AGENTS.md` §Documentation is amended to match; the "that stays open" sentence in §Development methodology is removed, because it is no longer open.

3. **`docs/remediation/findings.yml` is the authority for audit-finding status**, and only for that. Spec progress does not live there, and finding status does not live in `tasks.md`. The two ledgers have separate id spaces (`<SPEC>` acceptance criteria versus `<DIM>-<NN>` findings) and must not be merged.

4. **`docs/superpowers/plans/` is a historical execution record, not a progress authority.** A plan describes how one pass of work was carried out. It is append-only and must never be consulted to answer "what is the current state".

5. **The decision is only honest once the ledger is true.** Checkboxes are reconciled against evidence on disk, and a CI gate keeps them reconciled — advisory first, blocking once the first reconciliation passes cleanly. Declaring `tasks.md` authoritative while leaving 256 stale boxes would repeat the exact failure this ADR closes.

## Consequences

### Positive

- One question, one answer. A reader or agent asking "is this built" now has a single place to look, and `AGENTS.md` no longer argues with itself.
- The ambiguity is closed in the direction that matches reality: `tasks.md` already sits beside the acceptance criteria it tracks, whereas the issue tracker has never existed.
- The gate makes the guarantee mechanical rather than aspirational. Every previous attempt to keep these boxes current relied on discipline alone, and discipline is exactly what failed.
- Scope boundaries between the three ledgers are written down before a fourth one gets invented.

### Negative

- Checking a box becomes part of the merge ritual for spec-implementing work. That is a real ongoing cost, and it will occasionally be forgotten — the gate reduces this to a caught failure rather than silent drift, but does not eliminate it.
- `tasks.md` cannot answer cross-spec questions ("everything open across all 28 specs") without a tool to aggregate it. An issue tracker would. We accept this; the aggregation is a small script if it is ever needed.
- Reconciling 256 checkboxes is a real one-off cost, carried by workstream W-KIRO.
- The gate can only check what is mechanically checkable — that a named file or test exists. It cannot confirm that the code *satisfies* the acceptance criterion. A checked box still asserts human judgement, and the gate must not be mistaken for verification of correctness.

## Reversal

If an issue tracker is adopted later, supersede this ADR: move progress to the tracker, demote `tasks.md` to planning-only in `AGENTS.md`, and delete the reconciliation gate rather than leaving it enforcing a ledger nobody maintains. Do not leave both systems live — dual ownership of progress is the condition this ADR exists to end.
