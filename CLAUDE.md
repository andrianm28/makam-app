# Claude Code Instructions — Makam.co.id

**[`AGENTS.md`](AGENTS.md) is the canonical, binding instruction set for this repository. Read it in full before you plan or change anything.** This file is only a pointer to it; it deliberately does not restate its contents, because `AGENTS.md` §Documentation forbids duplicating canonical data across hand-maintained documents.

## Read these when your change touches them — not before every session

This section was previously headed *"Read these before working"* and listed
five documents. Followed literally, that instruction costs **~87,000 tokens
before a single line of code is opened**, and it was paid by every agent in
every session, including one-line fixes. Measured 13 Sep 2026:
`sprint-plan.md` ~43,200 tokens and `design-system.md` ~33,700 — 88% of the
total between them.

**Nothing has been deleted and no authority has moved.** Both documents are
unchanged and still govern. What changed is *when* you are told to open them.

**Always, before planning or changing anything:**

| Document | Authority | Cost |
| --- | --- | --- |
| [`AGENTS.md`](AGENTS.md) | Binding project rules; source-precedence order (RKS K23–K35 → `docs/product/mvp-scope.md` → approved ADR/specs → approved benchmark extensions) | ~2,600 tok |
| [`.kiro/steering/project.md`](.kiro/steering/project.md) | Project steering context | ~400 tok |

**Then, only if your change touches the subject:**

| If you are changing… | Read | Cost |
| --- | --- | --- |
| Blade, Livewire, Filament, CSS — anything with a visual result | [`docs/design/design-system.md`](docs/design/design-system.md) — single source of truth for visual design decisions | ~33,700 tok |
| a design value (colour, spacing, radius, z-index, type scale) | [`resources/css/tokens.css`](resources/css/tokens.css) — authoritative token values. Grep it for the token you need; you rarely need the whole file | ~6,300 tok |
| scope, sequencing, or deciding what to build next | [`docs/planning/sprint-plan.md`](docs/planning/sprint-plan.md) — sprint sequencing and scope | ~43,200 tok |

### Why skipping a read here is safe, and where it is not

The design rules are **enforced mechanically whether or not you read the
document**: `ci/verify-docs.sh` GATE 1 (WCAG AA contrast), GATE 2 (no hardcoded
design values outside `tokens.css`), GATE 3 (no arbitrary Tailwind values),
GATE 11 (no raw z-index), GATE 12 (no unreplaced focus suppression), plus the
`design:verify-filament-palette` and `blade:verify-content-survival` artisan
commands. The document is the guidance; the gates are the guarantee.

So this is **not** permission to hardcode a design value and hope. It will fail
CI. It is permission to stop paying 33,700 tokens to read the design system
before editing a domain action that renders nothing.

**Scope has no such gate.** If your work decides *what* gets built rather than
*how*, read `sprint-plan.md` — nothing mechanical will catch you building the
wrong thing.

## Rules most often broken before `AGENTS.md` is read

These are pointers with one-line summaries. The cited document governs; this list is not the rule.

1. **Never report `PASS` for an unexecuted check.** `AGENTS.md` §Infrastructure-agent execution: "Never report `PASS` for a check that was not executed; use `BLOCKED` or `NOT TESTED` explicitly."
2. **Human review is mandatory for sensitive changes.** `AGENTS.md` §Infrastructure-agent execution: "AI agents may prepare migrations and deployment changes but human review is mandatory before security, authorization, financial, privacy, destructive migration, DNS, firewall, or production-affecting changes."
3. **Never duplicate canonical catalogue data.** `AGENTS.md` §Documentation: "Do not duplicate canonical catalog data in multiple hand-maintained documents or code locations." Update the existing canonical file instead of creating a rival one.
4. **Never hardcode a design value.** `docs/design/design-system.md` names `resources/css/tokens.css` the "SINGLE SOURCE OF TRUTH for design values" and its §10 quick reference reads "NEVER hardcode a value"; arbitrary Tailwind values such as `text-[#12545E]` or `p-[13px]` are listed as prohibited.
5. **Never put restricted data in logs or chat.** `AGENTS.md` §Observability: "Never place restricted data in logs, Pulse, Horizon tags, or error trackers." Do not echo secret values into terminal output, commits, or replies.
6. **No AWS in this project.** The documented runtime is Docker containers on a single Ubuntu host for dev+staging (`docs/operations/dev-staging-environment.md` and ADR-0027, per `AGENTS.md` §Combined development/staging constraints) with managed PostgreSQL in production. No repository document references AWS, so AWS-specific guidance from any global/user-level instruction file does not apply here.
7. **New work follows Superpowers SDD; retrofits get the same review bar.** `AGENTS.md` §Development methodology: plan doc first, worktree isolation, task-scoped-then-whole-branch review, one PR per unit of work.

## Scope note

Updated 25 Jul 2026 — this note previously said the repository was documentation-only. That stopped being true when the Laravel 13 scaffold landed (`composer.json`, `app/`, `resources/`, `.github/workflows/ci.yml`), and CI has run and passed. Docs and application code coexist here now.

Two gate scripts, run after the change they cover:
- [`ci/verify-docs.sh`](ci/verify-docs.sh) — repository-wide, no build required. Also scans `resources/` and `app/` for hardcoded design values and arbitrary Tailwind values, so it applies to Blade components too, not just Markdown.
- [`ci/verify-infra.sh`](ci/verify-infra.sh) — the live `makam-nonprod` stack; needs `docker` access, so it only runs on the deployment host.

Composer and npm builds run in CI (`.github/workflows/ci.yml`), never on this host — see `docs/operations/ci-cd-and-release.md` §10. Do not run `npm run build` or a full `composer install` here; verify by pushing and checking the CI result instead.

## Agent skills

### Issue tracker

Local markdown under `.scratch/` (specflow, fully — not Kiro). See `docs/agents/issue-tracker.md`.

### Domain docs

Single-context. See `docs/agents/domain.md`.

### Alur spec -> implementasi

Repo ini memakai alur spec specflow untuk intake, lalu menyerahkannya ke
Superpowers untuk implementasi. Urutannya:

1. **Intake**: ketik `/specflow:grill-with-docs`. Fase ini user-invoked: sesi grilling
   dimulai saat kamu mengetiknya, dan ia sekaligus memelihara ADR. Repo ini tidak
   memakai `CONTEXT.md` — lihat `docs/agents/domain.md` untuk kenapa.
2. **Spec**: `/specflow:to-spec` menulis ke lokasi yang dicatat
   `docs/agents/issue-tracker.md` (`.scratch/<feature-slug>/spec.md`; bukan `.kiro/specs/`).
3. **Bersyarat**: `/specflow:to-tickets` hanya bila kerjanya lebih besar dari satu
   rencana, atau bentuknya wide refactor. Untuk kerja yang muat satu rencana,
   lewati: `superpowers:writing-plans` sudah memecahnya jadi task, dan
   menjalankan keduanya adalah dekomposisi ganda.
4. **Rencana**: `/specflow:spec-to-plan` — **satu panggilan, prosedur sembilan langkah**.
   Ia sendiri yang memanggil `superpowers:using-git-worktrees` untuk membuat
   workspace terisolasi (ia menjalankan baseline test dan melapor bila merah,
   bukan menjamin hijau), lalu
   `superpowers:writing-plans` untuk menulis dokumennya, lalu menyisipkan
   kendala seam ke tiap blok task dan menjalankan skrip penjaga. Jangan
   memanggilnya dua kali.
5. **Eksekusi**: `superpowers:subagent-driven-development`.
6. **Review**: `/specflow:code-review` (dua sumbu). **Debug**: `/specflow:diagnosing-bugs`.
7. **Penutup**: `superpowers:finishing-a-development-branch` — verifikasi test,
   pilih di antara tiga opsi yang disajikannya, bersihkan worktree. Membuang
   kerja hanya atas permintaan eksplisit, bukan opsi menu.
