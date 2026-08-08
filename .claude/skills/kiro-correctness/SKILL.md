---
name: kiro-correctness
description: Derive property-based tests from EARS requirements — universal invariants validated across many generated inputs, with shrinking to a minimal failing case. Use when proving an implementation matches its spec, writing regression protection for a bugfix spec's unchanged-behaviour list, or when asked about property-based testing, invariants, or "correctness".
---

# Correctness — property-based testing from requirements

Source: [kiro.dev/docs/specs/correctness](https://kiro.dev/docs/specs/correctness/).

> **Honesty note.** kiro.dev lists Correctness as **IDE-only**, and its automatic property
> extraction and generator tooling are part of that product. This skill is the manual discipline:
> derive the properties yourself, and write them as real tests in this repo's PHPUnit suite. Do
> not claim Kiro's PBT engine ran.

## What a property is

> "A universal statement about how your system should behave" — an invariant that holds regardless
> of the specific data.

Example from the docs: *"For any authenticated user and any active listing, the user can view that
listing."* Example-based tests check one input; a property checks a rule across many.

## EARS → property

EARS criteria translate almost mechanically:

| EARS | Property |
|---|---|
| `THE SYSTEM SHALL <behaviour>` | invariant true for all valid inputs |
| `WHEN <event> THE SYSTEM SHALL <behaviour>` | for all inputs where the event holds, the behaviour holds |
| `WHILE <state> THE SYSTEM SHALL <behaviour>` | for all inputs, in that state, the behaviour holds |
| `THE SYSTEM SHALL NOT <behaviour>` | for all inputs, the behaviour never occurs — the strongest kind |
| `SHALL CONTINUE TO <behaviour>` | regression property: holds before **and** after the change |

## Why it is worth the effort

- One property yields many cases; you stop being limited by the examples you imagined.
- Properties survive refactors better than examples.
- Random inputs reach edge cases nobody would deliberately write.
- **Defining the property forces the requirement to be precise** — often the real payoff, because a
  criterion you cannot state as a property is usually a criterion that was too vague.

**Shrinking**: when a case fails, reduce the input to the minimal reproduction so the cause is
obvious rather than buried in noise. Without a generator library, do this by hand — narrow the
failing input before reporting it.

## Limits — state these plainly

- Provides **evidence, not proof**.
- A weak property passes while the behaviour is still wrong. The property is the test.
- External services and non-deterministic behaviour resist PBT.
- Not every requirement maps cleanly to a property. Say which ones do not, rather than inventing a
  weak property to fill the row.

## Doing it in this repo

There is no PBT library in `composer.json`. Two honest options:

1. **Bounded exhaustive / table-driven** — enumerate the input space where it is small and finite,
   and assert the invariant across all of it. Much of this domain qualifies: the 5 launch cities,
   12 service codes, 9 product codes, 6 FAQ categories, 17 gates, and every closed-list constant
   class in `app/Domain/**`. This repo already does it — `ServiceCodeDriftTest`,
   `CemeteryCapabilityModeClosedListTest`, and `PriceVersioningTest`'s "every known service has a
   current price version" loop are properties in all but name.
2. **Add a generator library** — a real dependency change. Needs `composer.json` + lockfile edits,
   which only CI can install and verify. Propose it; do not add it silently.

High-value properties for this codebase:

- For **any** gate id, `FeatureGateResolver::isOpen()` returns `false` unless a real open row backs
  it (deny-by-default, including unknown and misconfigured ids).
- For **any** actor with no scope grants, every scoped query returns zero rows.
- For **any** committed domain mutation that requires audit, exactly one `audit_events` row exists.
- For **any** unpublished FAQ article, no public view or public search result contains it.
- For **any** outbox event, publishing twice never produces two deliveries.

## Bugfix use

For a `bugfix.md`, write three property sets: bug reproducible **before** the fix, resolved
**after**, and every `SHALL CONTINUE TO` statement still holding. The failing test must fail before
the fix — otherwise it proves nothing. See `kiro-bugfix-spec`.
