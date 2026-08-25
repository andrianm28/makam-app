# npm audit fix: nanoid (applied 24 Aug 2026, CI verification pending)

## Status

**Applied 24 Aug 2026 — CI verification still pending.** *Corrected on this date; the original
"prepared, not executed" status below is kept verbatim for the record.*

The fix landed by a different route than the one this document originally prescribed. Rather than
running `npm audit fix` (still impossible on this host), `package.json` and `package-lock.json`
were hand-edited to pin `nanoid` via an `overrides` entry, using the **real** integrity hash and
tarball URL fetched directly from `https://registry.npmjs.org/nanoid/3.3.18` — not a guessed one.
That hash was independently re-fetched and compared character-for-character by four separate
parties before the change was accepted, precisely because the risk this document names (a
hand-edited hash silently breaking `npm ci`) is real. `.github/workflows/ci.yml`'s `|| true`
escape hatch and its TODO comment were removed in the same change, so the audit step now genuinely
gates.

What is **not** yet proven: no `npm ci`/`npm audit` has run against this state, because this host
has no npm toolchain. Whether the audit actually reports clean is confirmed only by the first real
CI run on the branch carrying this change. `docs/testing/release-gates.md`'s §G "No unresolved
critical/high security issue" box is deliberately left **unchecked** until that run confirms it.

*Original status, kept verbatim:* **Prepared, not executed.** This repo's `CLAUDE.md` forbids
running `npm install`/`npm audit fix`/`npm run build` on this host — "Composer and npm builds run
in CI... never on this host... verify by pushing and checking the CI result instead." Regenerating
`package-lock.json` requires running real `npm` to compute a correct integrity hash for the bumped
entry; a hand-edited hash risks silently breaking `npm ci` in CI. This fix needs a human running
the two commands below, not an agent session.

## The finding

Confirmed against a real recent CI run's "Dependency audit" job (not run locally):

```
nanoid  <3.3.18
Severity: high
nanoid: custom generators can loop indefinitely when size is zero
  - https://github.com/advisories/GHSA-2v37-7h3g-55p8
fix available via `npm audit fix`
node_modules/nanoid
```

`nanoid` is a transitive dependency (not listed in `package.json` directly) — some direct
dependency's own `package.json` already permits `^3.3.16`, which already includes the fix version
`3.3.18`. This is a lockfile-only bump, not a `package.json` change and not a breaking change.

## The fix

> **Superseded 24 Aug 2026** — see Status above. The pin was applied by hand via an `overrides`
> entry with a registry-verified integrity hash, not by `npm audit fix`. The procedure below is
> kept as the reference for how a human with a real npm toolchain should re-derive or re-verify
> the same result.

Run these two commands locally (with real npm + network access), then commit the resulting
`package-lock.json` diff:

```bash
npm audit fix
npm audit --audit-level=high
```

Expected: the second command reports 0 vulnerabilities at or above `high` severity — matching
what `.github/workflows/ci.yml`'s own audit-level threshold checks. Confirm `git diff
package-lock.json` shows ONLY the `nanoid` entry's version/resolved/integrity fields changing —
if `npm audit fix` pulls in unrelated version bumps, review those separately before committing (a
security-fix commit should stay scoped to the security fix).

## After the fix lands

**Done 24 Aug 2026.** `.github/workflows/ci.yml`'s `npm audit --audit-level=high || true # TODO:
fail once the baseline is clean` step lost both the `|| true` escape hatch and its TODO comment in
the same change that pinned `nanoid`; it now reads `npm audit --audit-level=high` and genuinely
enforces the audit level it claims to. The original reasoning, for the record: leaving `|| true`
in place after the baseline is clean defeats the fix.
