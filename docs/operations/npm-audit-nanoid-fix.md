# npm audit fix: nanoid (prepared, not executed)

## Status

**Prepared, not executed.** This repo's `CLAUDE.md` forbids running `npm install`/`npm audit
fix`/`npm run build` on this host — "Composer and npm builds run in CI... never on this host...
verify by pushing and checking the CI result instead." Regenerating `package-lock.json` requires
running real `npm` to compute a correct integrity hash for the bumped entry; a hand-edited hash
risks silently breaking `npm ci` in CI. This fix needs a human running the two commands below,
not an agent session.

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

Once `package-lock.json` is committed with the real bump, `.github/workflows/ci.yml`'s
`npm audit --audit-level=high || true # TODO: fail once the baseline is clean` step's `|| true`
escape hatch and its own TODO comment should be removed in the SAME commit (or an immediate
follow-up) — the whole point of this fix is to make that step genuinely enforce the audit level
it claims to. Leaving `|| true` in place after the baseline is clean defeats the fix.
