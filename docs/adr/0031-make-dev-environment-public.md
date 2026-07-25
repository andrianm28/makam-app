# ADR-0031: Remove Access Restriction from dev.makam.co.id

## Status

Accepted.

## Context

[`docs/operations/dev-staging-environment.md`](../operations/dev-staging-environment.md) §5 (line 75) and `ci/verify-infra.sh` GATE I9 both required `dev.makam.co.id` to refuse unauthenticated access (VPN, IP allowlist, or reverse-proxy `auth_basic`), the same requirement recorded in [ADR-0027](0027-combine-dev-staging-on-ubuntu22-2v4g.md)'s combined dev/staging model and in `sprint-plan.md`'s S2-T5 deliverable. This was implemented 25 Jul 2026 as HTTP basic auth on the `dev.makam.co.id` nginx vhost, verified returning `401` without credentials.

Later the same day the user explicitly requested making the dev environment public ("buat env dev publik"). Before acting, the documented conflict was raised directly: removing the restriction would contradict dev-staging-environment.md §5 and would fail GATE I9. The user was offered four labelled options, including one explicit option describing exactly this policy violation, and selected it ("Hapus auth, benar-benar publik" — remove auth, make it truly public).

Per this project's own escalation rule (raise a documented conflict once; if the user reaffirms the flagged option, that is their decision to make), the change proceeds rather than being silently refused or repeatedly re-litigated.

## Decision

`dev.makam.co.id` no longer requires authentication or IP restriction to reach. Specifically:

1. `auth_basic`/`auth_basic_user_file` removed from `/etc/nginx/sites-available/dev.makam.co.id.conf`.
2. `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` is retained — the environment is public but still asked not to be crawled/indexed. This is unchanged and still enforced.
3. `stg.makam.co.id` is **not** covered by this decision. §5's staging access requirement (limited stakeholder/UAT access, authentication where appropriate) stands as-is; this ADR concerns `dev.makam.co.id` only.
4. `ci/verify-infra.sh` GATE I9 is updated to assert the new expected state (200 without credentials is now correct; 401/403 would indicate an unintended regression back to restricted access) while continuing to assert the `noindex` header is present.
5. `docs/operations/dev-staging-environment.md` §5 (line 75) is updated with a dated note recording this reversal rather than silently rewritten, per this repository's established pattern for policy changes.

## Consequences

### Positive

- Removes a friction point for whoever needs to reach the dev environment (no VPN/allowlist/credential to distribute or maintain).
- One less credential to rotate, store, and keep in sync with team membership.

### Negative

- `dev.makam.co.id` is reachable by anyone who finds the hostname, including scanners — the existing nginx access log already shows automated probes for `.env`, `.git`, backup files, and SSH keys against this host (unrelated vhosts, same server, observed 25 Jul 2026). The dev environment must not hold real user data, real credentials, or anything with a value beyond "safe to be public" as a result of this decision — this constraint already existed via §4's synthetic-data-only rule but now carries more direct exposure.
- Any secret, debug endpoint, or verbose error page unintentionally left reachable on dev is now exposed to the public internet, not just to allowlisted/authenticated parties. Debug mode, verbose error pages, and admin panels on this host should be reviewed for whether they are safe to expose before further deployment.
- Diverges from ADR-0027 and the original S2-T5 deliverable, which specified access restriction for dev as a condition of the combined dev/staging model. This ADR supersedes that specific clause for `dev.makam.co.id` only; ADR-0027's other conditions (shared services, isolation, non-production status) are unaffected.

## Reversal

If scanner traffic, an exposure incident, or a future review decides the tradeoff was wrong, restoring `auth_basic` on the vhost, reverting GATE I9, and reverting the dev-staging-environment.md note is a single small change — the same shape as this one, in reverse. No data migration or structural dependency was created by this decision.
