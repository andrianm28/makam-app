# Issue tracker: Local Markdown (specflow)

> _Contradicts ADR-0038 (tasks-md-owns-per-spec-progress-no-external-issue-tracker), by explicit owner direction 20 Sep 2026: new specs follow specflow, not Kiro. ADR-0038 needs a superseding ADR to close the loop; until then this file is the operative convention and dual ownership is explicitly rejected (see Frozen below)._

Issues and specs for new work live as markdown files in `.scratch/`.

## Conventions

- One feature per directory: `.scratch/<feature-slug>/`
- The spec is `.scratch/<feature-slug>/spec.md`
- Implementation plans are written to `docs/superpowers/plans/` unless an ADR in
  this repo says otherwise; record the actual location here, because
  `/specflow:spec-to-plan` reads this line and passes it to
  `superpowers:writing-plans`, whose own default would otherwise win
- Implementation issues are one file per ticket at `.scratch/<feature-slug>/issues/<NN>-<slug>.md`, numbered from `01`, never a single combined tickets file
- Triage state is recorded as a `Status:` line near the top of each issue file. specflow tidak mengirim skill `triage`, jadi `docs/agents/triage-labels.md` tidak dibuat; pakai string status apa pun yang repo ini sudah pakai.
- Comments and conversation history append to the bottom of the file under a `## Comments` heading

## Frozen (not authoritative for new work)

- `.kiro/specs/*/{requirements,design,tasks}.md` is a frozen archive. Do not consult it to answer "what is the current state" for new work and do not update its checkboxes.
- `docs/remediation/findings.yml` remains the read-only record of past audit findings; new findings go to `.scratch/` per the conventions above.

## When a skill says "publish to the issue tracker"

Create a new file under `.scratch/<feature-slug>/` (creating the directory if needed).

## When a skill says "fetch the relevant ticket"

Read the file at the referenced path. The user will normally pass the path or the issue number directly.

## Baseline test route (recorded 20 Sep 2026, spec-to-plan step 4)

- `bash ci/verify-docs.sh` runs on this host with no build (verified ALL PASS in worktree).
- PHP tests (phpunit/artisan) CANNOT run on this host: worktrees ship without `vendor/` and installs are forbidden here per `CLAUDE.md`; the main checkout's `vendor/` requires PHP >= 8.5 while the host provides PHP 8.3.6. No app container is running. Run the PHP baseline inside the project's PHP 8.5 container or CI (`CI php job`) instead, e.g. `php artisan test tests/Feature/Livewire/Public/Faq/FaqArticleDetailRouteTest.php`. Never assume green from an unrunnable baseline.
