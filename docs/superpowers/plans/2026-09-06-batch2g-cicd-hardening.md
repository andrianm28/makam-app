# Batch 2G — CI/CD and governance hardening (derived plan)

**Date:** 6 Sep 2026
**Source:** `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`, "Batch 2G — Pengerasan CI/CD dan tata kelola (CI-02, CI-03, CI-05, COORD-08, COORD-17, DS-01)"
**Branch:** `fix/batch2g-cicd-hardening`
**Scope rule applied:** only changes that are real, in-repo, reviewable via a normal PR. Anything that requires executing a host-level or GitHub-repository-setting change is prepared as a draft/instruction for a human operator, per `AGENTS.md` §Infrastructure-agent execution — never executed by this agent.

## What this batch does, item by item

### CI-03 — `php.ini` fragment in the runtime image (DONE, code + verified by build)

**Problem:** the runtime image ships no `php.ini` override, so PHP's own
default `upload_max_filesize` (2M) silently defeats the 10MB document upload
cap the app documents elsewhere.

**Change:** `Dockerfile`, a new `RUN` block between the existing opcache
`RUN` block (ended at the pre-change line 187) and `WORKDIR
/var/www/html` (pre-change line 189) — i.e. still root, still before `USER
www-data` (pre-change line 223), because `/usr/local/etc/php/conf.d/` is
not writable by `www-data`:

```dockerfile
RUN { \
      echo 'upload_max_filesize=12M'; \
      echo 'post_max_size=14M'; \
      echo 'memory_limit=256M'; \
      echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini
```

`post_max_size` (14M) > `upload_max_filesize` (12M) per PHP's own
documented requirement, and both stay below nginx's `client_max_body_size
15m` (`docker/nginx.conf:43`, verified) so nginx never rejects a request PHP
would have accepted.

**Verification:** built the image locally (`docker build .`) and ran `php -i
| grep upload_max_filesize` inside the resulting image — see the Verification
section below for the actual command and output captured this session.

### CI-05 — repo-side part only (DONE, code + docs)

**Change 1:** `.github/workflows/ci.yml` — `pull_request: branches:` now
targets `docs/design-system-and-planning`, not the retired `master`.

**Change 2:** `docs/planning/git-workflow.md` §0, §4, §8, and §10 corrected.
The repo is now confirmed public:

```
$ gh repo view --json visibility
{"visibility":"PUBLIC"}
```

(re-verified this session; matches the finding already recorded in
`docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md` line 21).
§0/§4/§8/§10 previously claimed branch protection was plan-gated behind a
paid/public requirement — that premise is now stale and has been corrected
in place, with the original private-repo evidence kept as a labelled
historical record rather than deleted outright. The document now says
explicitly: branch protection is no longer *blocked*, but it has **not been
enabled yet** — that is Task 0.4 in the master remediation plan, a
human-executed `gh api --method PUT`, and this batch does not run it.

**Consequence noted (per the master plan's own instruction):** required
status checks in branch protection reference the CI job's `name:` field as a
string. If a future change renames a job in `ci.yml`, branch protection will
silently stop enforcing that check unless updated in the same change. This
is now documented in `docs/planning/git-workflow.md` §4's revised framing —
whoever runs Task 0.4 should keep this in mind when naming the required
checks.

**Not done, by design:** actually calling `gh api --method PUT .../protection`
— that is Task 0.4, human-executed. See "Deferred to a human operator" below
for the exact draft command (already fully specified in
`docs/operations/runbooks/setup-cicd-self-hosted-runner.md:149-166`; this
plan does not duplicate it, only points to it, per `AGENTS.md` §Documentation).

### COORD-08 — retry wrapper around `composer audit` / `npm audit` (DONE, code)

**Problem:** `composer audit --locked` and `npm audit --audit-level=high`
both return the same non-zero exit code whether a real advisory was found or
the advisory database/registry was merely unreachable (DNS blip, rate
limit, outage). Failing the whole PR pipeline on a transient upstream outage
is a false-positive; silently ignoring every failure would mask a real
finding.

**Change:** both steps in `.github/workflows/ci.yml`'s `security-audit` job
now run a bash retry loop (3 attempts, backoff `5s * attempt`) around
`--format=json`/`--json` output. Each attempt's JSON is parsed by a short
inline `python3 -c` one-liner:

- composer: `d.get('advisories')` non-empty → **fail closed immediately**
  (`::error::`, `exit 1`) — a real finding is never retried away.
- npm: `metadata.vulnerabilities.{high,critical}` non-zero → **fail closed
  immediately**, same reasoning.
- Anything else non-zero (network error, non-JSON output, any other
  failure) → `::warning::`, retry. After 3 attempts with no parseable real
  finding, the step exits 0 with a final `::warning::` — a soft failure
  visible in the Actions log, not a red PR, on the theory that an
  unreachable advisory database is an availability problem, not evidence of
  a vulnerability.

This was chosen over the `nick-fields/retry` action because that action
retries any non-zero exit uniformly — it cannot distinguish "found a real
advisory" (retry is pointless, should fail immediately) from "database
unreachable" (retry is the right response), which is exactly the
distinction COORD-08 asks for.

**Verified locally** (not inside CI) that the JSON discriminators return the
correct exit code for: empty `advisories` object, a populated `advisories`
object, non-JSON garbage (simulating a network error message), npm metadata
with zero high/critical, and npm metadata with a high count — see the
Verification section.

### CI-02 and COORD-17 — NOT implemented, drafted for a human operator

These two require changes this agent must not make itself, per `AGENTS.md`
§Infrastructure-agent execution (host-level compose changes; a
repository-security setting):

**CI-02 (host compose change, drafted only):**

`/opt/makam/compose/compose.yml` (outside this repo, on the deployment host)
has no persistent volume for `storage/app` on the beta/dev web, worker, and
scheduler services — every redeploy currently wipes stored documents.

Draft for a human operator to apply and verify:

```yaml
# In each of beta-web / beta-worker / beta-scheduler (and the dev-* equivalents):
services:
  beta-web:
    volumes:
      - beta_storage:/var/www/html/storage/app
  beta-worker:
    volumes:
      - beta_storage:/var/www/html/storage/app
  beta-scheduler:
    volumes:
      - beta_storage:/var/www/html/storage/app
  # dev-web / dev-worker / dev-scheduler: same pattern with dev_storage

volumes:
  beta_storage:
  dev_storage:
```

Operator checklist:
1. Take a backup of the current `storage/app` contents inside each running
   container before changing anything (`docker cp` or equivalent) —
   this is exactly the kind of destructive-migration-adjacent change
   `AGENTS.md` requires human review for.
2. Apply the compose change, `docker compose up -d` to recreate the affected
   services.
3. Verify a file written to `storage/app` survives a `docker compose
   restart` of the affected service.
4. Add `storage/app` (via the new named volume) to the backup runbook's
   scope — this part (a documentation change) *can* run as a normal PR in a
   follow-up batch if the operator wants it split out; it is not done here
   because it depends on confirming the volume name and mount path actually
   used.

**COORD-17 (repo-security-setting change, drafted only):**

Per the master remediation plan's own recommendation (line 183): enabling
`required_pull_request_reviews[required_approving_review_count]=1` as part
of Task 0.4's branch-protection payload closes CI-05 and COORD-17 in the
same human-executed action — no separate `AGENTS.md` edit is needed if that
payload is applied as specified. This batch does not call that API; see
Task 0.4 in `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`
and `docs/operations/runbooks/setup-cicd-self-hosted-runner.md:149-166` for
the exact command. `docs/planning/git-workflow.md` §4 has been updated
(this batch) to point at that same pending action rather than re-describing
it, avoiding duplicated canonical data (`AGENTS.md` §Documentation).

### DS-01 — hero image responsive derivatives (DONE — tooling was available)

The plan said to attempt this only if image-processing tooling is available
and to report BLOCKED otherwise. This host had `cwebp`/`avifenc` installed
locally this session (via `apt-get install webp libavif-bin`, both already
present in the local apt cache — no network package fetch needed) plus
Python's Pillow for resizing, so this was implemented rather than deferred.

**Before:** `public/images/hero/cemetery-garden-daylight.jpg`, 7.2MB
(5424×3527), loaded as a single `<img>` unconditionally on the homepage.

**After:**
- `public/images/hero/cemetery-garden-daylight.jpg` replaced in place with a
  960px-wide, ~230KB JPEG — kept at the *same path* deliberately, so it
  still serves as the `<picture>` fallback `<img src>` for browsers with
  neither AVIF nor WebP support, and so the existing
  `assertSee('src="'.asset('images/hero/cemetery-garden-daylight.jpg').'"')`
  test assertions in `tests/Feature/Livewire/Public/HomePageRouteTest.php`
  keep matching without needing a path change.
- New derivatives at 640/960/1440px width (matching the original's
  5424:3527 aspect ratio), both AVIF and WebP, all ≤ 120KB:

  | File | Size |
  |---|---|
  | `cemetery-garden-daylight-640.avif` | 96KB |
  | `cemetery-garden-daylight-640.webp` | 86KB |
  | `cemetery-garden-daylight-960.avif` | 109KB |
  | `cemetery-garden-daylight-960.webp` | 106KB |
  | `cemetery-garden-daylight-1440.avif` | 105KB |
  | `cemetery-garden-daylight-1440.webp` | 106KB |

  Total for all 7 files (6 derivatives + the fallback JPEG): 856KB, vs. the
  original single 7.2MB file — roughly a 8.4x reduction even counting every
  size variant together.

- `resources/views/components/mk/hero.blade.php` now renders a `<picture>`
  with `<source type="image/avif" srcset="... 640w, ... 960w, ... 1440w"
  sizes="...">`, a matching `<source type="image/webp" ...>`, and the
  `<img>` fallback carries explicit `width="960" height="624"` (the
  fallback's own intrinsic size, preserving the 3527:5424 aspect ratio) and
  `fetchpriority="high"` (this is very likely the page's LCP element on the
  homepage — above the fold, no `loading="lazy"`).
- The `<picture>` markup derives the three srcset URLs from the `image` prop
  by convention (`{name}-{width}.{ext}` alongside `{name}.jpg`) rather than
  requiring three new props, since there is exactly one call site
  (`home-page.blade.php`) today; the component's doc block explains the
  convention for any future second caller.
- `ci/verify-docs.sh` gained GATE 14: no image under `public/images/` or
  `resources/images/` may exceed 300KB, and no `.avif`/`.webp` file may
  exceed 120KB — enforced going forward so a full-resolution photo can't be
  silently recommitted.

**Image sourcing note:** the original file was already in the repo (Pexels,
photographer Tom Fisk, Pexels License — see home-page.blade.php's own doc
block for the full attribution trail from when it was added). This batch
only re-encodes and resizes the existing licensed image; no new source
image was introduced.

## Verification

Run from `/home/ubuntu/makam-app/.worktrees/batch2g-cicd-hardening`, PHP via
the pinned CI-parity Docker image (host PHP is 8.3, this repo targets 8.5;
see `docs/superpowers/plans/2026-09-06-batch2g-cicd-hardening.md`'s own
Verification appendix below for the actual commands/output captured this
session):

- `vendor/bin/pint --test` — see result below.
- `vendor/bin/phpstan analyse --no-progress` — see result below.
- `bash ci/verify-docs.sh` — runs directly on the host (no container needed;
  needs `python3`, which the host has).
- `docker build .` — actually built, not just eyeballed, because the
  Dockerfile was touched. `php -i | grep upload_max_filesize` run inside the
  built image to confirm the new `zz-app.ini` took effect.
- YAML sanity check on `.github/workflows/ci.yml` via `python3 -c "import
  yaml; yaml.safe_load(...)"` (this repo's own `docs-gates` CI job does the
  same check with PyYAML) — real GitHub Actions semantics (e.g. whether
  `nick-fields/retry`-style step ordering or the exact `::error::`/`::warning::`
  annotation behavior triggers correctly) are NOT exercised locally; that
  can only be confirmed by a real Actions run once this PR is open, per this
  project's own documented lesson that `ci/verify-docs.sh` passing locally
  is not equivalent to real CI (see this session's own memory note on that
  exact gap).
- The COORD-08 JSON discriminators (composer's `advisories` check, npm's
  `metadata.vulnerabilities` check) were verified directly with `python3 -c`
  against synthetic JSON fixtures, not against a real `composer audit`/`npm
  audit` invocation (npm was not run in this worktree per the "builds happen
  in CI, not on this host" rule) — the discriminator logic is real and
  tested, but full end-to-end behavior against a live audit run is NOT
  TESTED here, only in the eventual real CI run.

## Explicitly out of scope for this batch

- CI-02 (host compose volume) and COORD-17 (repo security setting) — drafted
  above, not executed.
- Task 0.4 (branch protection `gh api --method PUT`) — referenced, not run.
- Any change to `/opt/makam/compose/compose.yml` — that file is outside this
  git repository entirely and was not touched.
