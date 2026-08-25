# Public Beta Release — Makam.co.id

## Context

Makam.co.id is feature-broad and genuinely well-built: the 9-step booking wizard, marketplace,
renewal journey, cemetery directory, FAQ, memorial/QR, visitation, certificates, pre-need
interest, an admin Filament panel (~19 resources) and a vendor panel all exist, with ~2,754
tests passing and an 8-job CI producing immutable, digest-pinned GHCR images.

What has never been done is **going live**. Every deploy to date has gone to one
non-production Docker stack. The remaining gaps are almost entirely *wiring and go-live
configuration*, not architecture — but three of them mean the platform would not function as
a product on day one:

1. **The async spine never runs.** No queue worker, no scheduler in the compose stack, and
   `OutboxPublisher::publishBatch()` has zero production callers — no artisan command, no
   `Schedule::` entry. The transactional outbox writes rows nothing drains.
2. **Notifications never leave the app.** Only `LogChannel` and `NullChannel` exist. A
   customer books and hears nothing, ever.
3. **Fake data ships via migrations.** ~17 data-writing migrations install 10 fictional
   cemeteries, fictional deceased persons and dummy prices on every `migrate --force` — the
   documented deploy step. `robots.txt` is fully open, so Google would index it.

This plan takes the platform to a **public soft launch at `makam.co.id`** on the existing
host, with online payment running against the SumoPod sandbox under explicit beta labelling.

### Decisions taken (user, 18 Aug 2026)

| Decision | Choice |
|---|---|
| Payment | Keep SumoPod **sandbox** live, labelled loudly as simulated |
| Audience | Public, soft-launched (not promoted) |
| Environment | Promote the **existing** host; no new production infra |
| URL | App moves to **`makam.co.id`**, replacing the landing page |
| Catalogue data | Replace fake cemeteries with **real data sourced from public records** |
| Timeline | As fast as *safely* possible, risk accepted explicitly in writing |

### Recorded dissent — two decisions I'd urge reconsidering

The plan proceeds as directed. These are the two places the chosen path carries risk that
may not have been priced in, and both were independently flagged by a second design pass.

**1. Sandbox payments in front of real, bereaved customers.** A real person can complete a
booking for a real burial and "pay" through a sandbox that moves no money; the order is then
marked paid. That is a real service obligation with no payment and no contract behind it, and
grieving users are the least likely people to read a beta banner. `AGENTS.md` §Domain and
financial invariants already mandates the alternative — "Closed online-payment gate uses
manual fallback in Step 8" — which puts a human in the loop on every order, normally what you
*want* in a soft beta. **Phase 3 mitigations are labelling and daily reconciliation, not
prevention.** Reversing this later is a one-row gate change, no code.

**2. Publishing prices against real government cemeteries.** Jakarta TPU tariffs are set by
Perda DKI No. 1/2015: **Rp0–100,000 for three years** (Blok AA.I Rp100k, AA.II Rp80k, A.I
Rp60k, A.II Rp40k, A.III free), renewal at 50% then 100%. The seeded dummy prices are
`3,000,000 + index × 500,000` — **30–80× the real regulated tariff**. Publishing those against
real TPU names would be materially misleading, and there is active DPRD pressure to abolish
the retribution entirely. Bogor, Depok, Tangerang and Bekasi each have their own Perda, so
"real prices" means five municipal regulations. Separately, TPU are government-run: a private
platform presenting itself as able to book plots there without a partnership agreement is a
business exposure independent of the software. **Phase 2 Lane B therefore ships real names and
locations but no fabricated prices.**

---

## Critical path at a glance

```
Phase 0 (humans, day 0, all parallel) ──────────────────────────────┐
                                                                     │
Phase 1 lanes A–E (parallel, ~8–10 days elapsed) ───────────────────┤
  A  async spine + real email   ← A2 and A3 MUST ship together      │
  B  purge fake data + real data + empty states                     │
  C  identity, legal, indexing, beta banner                         │
  D  environment, backups, hardening                                │
  E  observability                                                  │
                                                                     │
Phase 2 gate (sequential, ~3 days) ─────────────────────────────────┘
  manual UAT · a11y fix · locale · ADR-0035 · go/no-go

→ Cutover to makam.co.id
```

**~19–21 focused engineering days; ~11–13 with three lanes in parallel. Realistic calendar
3–4 weeks, and the binding constraint is not engineering** — it is legal review (H3) and email
sending-domain warm-up (H4). A cold domain needs days before transactional mail reliably
reaches Gmail inboxes; a booking confirmation in the spam folder ships blocker #2 with extra
steps.

---

## Phase 0 — Human decisions (start today; external latency, no code)

These gate Phase 1 completion and nothing compresses them. **If only one thing happens today,
it is this list.**

| # | Decision needed | Unblocks |
|---|---|---|
| H1 | Real legal entity — PT name, NIB, registered address (or a written statement of who operates the service) | `CompanyInfo`, footer, both legal pages |
| H2 | Real support phone + WhatsApp + a mailbox that actually receives mail at `makam.co.id` | `ContactInfo`, UU PDP data-subject-rights channel |
| H3 | **Legal review of `/privasi` and `/syarat-ketentuan`** — removes the DRAFT label | Public launch |
| H4 | **Transactional email provider + sender domain** (SPF/DKIM/DMARC on `makam.co.id`) | Blocker #2 |
| H5 | S3-compatible object storage, **backups only** | Backups (OQ-4) |
| H6 | Where alerts go — a phone someone answers | Observability |
| H7 | Which cemeteries are real launch content, and confirmation that no pricing is published without a cited legal source | Lane B |

**OQ-4 is now a much smaller decision** than the docs imply: descoping document upload (D1
below) means object storage is needed for *backups only*. Cloudflare R2 or Backblaze B2 both
work with `docs/operations/examples/backup-staging.sh` as written. Biznet Gio / IDCloudHost are
the local options if you want Indonesian data residency — a defensibility judgement, not a
legal requirement for a private-sector controller under PP 71/2019.

---

## Scope decisions that shorten the path

**D1. Beta does not accept private documents (KTP/KK/death certificates).** Booking Step 7
upload is unimplemented anyway. Deciding it stays off removes the object-storage dependency
for uploads, removes the need to *write* a real malware-scanner adapter (2–3 days —
`config/document-vault.php` nulls `MockScanner` outside dev and the provider throws), and
removes the highest-severity slice of UU PDP exposure. Step 7 shows an honest "dokumen
dikumpulkan oleh tim kami setelah pemesanan" state; collection happens offline. Recorded as an
MVP §2 exception.

**D2. Close `G-DATA-01` (grave search) on beta.** Over a purged `grave_records` table, search
returns nothing for every query. Closing the gate shows the honest "belum tersedia" surface
instead of a search that always fails. Reopen when a real registry exists.

**D3. Beta runs on its own database `makam_beta`.** `makam_dev` holds demo data, open gates,
and accounts whose password is committed in git history. Beta gets a new database, user,
secret file and admin accounts.

**D4. `G-PAY-01` stays open** per the user's decision, with Phase 3 mitigations.

---

## Phase 1 — Blockers, before any public traffic

### Lane A — Async spine + real notifications (~4 days)

> **Sequencing hazard, do not get this wrong.** Today nothing drains the outbox, so
> `consumeOutboxEvent()` never runs and *no* `notification_deliveries` rows are created. The
> "DB says SENT when nothing was sent" failure is **not yet true** — it becomes true the moment
> a worker starts with `LogChannel` still bound, which is exactly the `AGENTS.md` §Notifications
> violation ("Do not claim delivery without delivery state") the `DeliveryState` enum exists to
> prevent. **A2 and A3 ship in the same PR**, or A3 ships with `NullChannel` bound (already
> written for this purpose; honestly records `UNAVAILABLE`).

The chain is 95% built and wired — `OutboxPublisher::publishBatch()` → `PublishOutboxEventJob`
→ `OutboxEventPublished` → `DispatchNotificationConsumerOnOutboxEventPublished` →
`ConsumeOutboxNotificationJob` → `DispatchNotification::consumeOutboxEvent()` →
`SendNotificationChannelJob` → `Channel::send()`, with retry, backoff and stale-claim reclaim.
Three links are missing.

- **A1 — outbox drain command** (0.5d). New `app/Console/Commands/OutboxPublishCommand.php`
  (`outbox:publish`), bounded loop on `publishBatch()`, modelled on
  `BookingPurgeStaleDraftsCommand`. Register in `routes/console.php`:
  `Schedule::command('outbox:publish')->everyMinute()->withoutOverlapping()->onOneServer();`
  Note `OutboxPublisher::claim()` hard-requires `pgsql`.
- **A2 — real mail channel** (1.5d, +0.5 risk). New
  `app/Platform/Notification/Channels/MailChannel.php` implementing the existing `Channel`
  contract; it must call `TemplateRenderer::render($version, [])` itself per the contract, and
  return `Failed(retryable: true)` on transient SMTP so `RetryFailedDeliveryJob` engages.
  **The one real unknown:** confirm `NotificationRecipient.recipient_ref` resolves to an
  addressable email before writing the channel — a missing resolution seam adds a day. Bind by
  config (mirroring `document-vault.php`), not hardcoded: add a `notification.channel` key and
  read it in `NotificationServiceProvider`. Beta gets `MailChannel`; CI/dev keep `LogChannel`.
- **A3 — worker + scheduler containers** (1d, ops). `docker/docker-entrypoint.sh` already
  execs any non-`web` first argument, so **no image change is needed**; service shapes exist in
  `docs/operations/examples/docker-compose.dev-stg.yml`. Add `beta-worker`
  (`queue:work --queue=critical,urgent,notifications,default --tries=3 --max-time=3600`,
  `stop_grace_period: 90s`) and `beta-scheduler` (`schedule:work`). Drop the example's
  `--stop-when-empty`. **Easy-to-miss env bug:** `config/queue.php` defaults
  `QUEUE_CONNECTION` to `database` — set `redis` in `.env.beta` or workers listen on Redis
  while jobs land in the `jobs` table. **Both need the `egress` network**, not just `backend`
  (`internal: true` silently kills outbound access) — they need SMTP and the payment sandbox.
- **A4 — verification** (0.5d). Walk a booking end to end on the beta host; confirm a real
  email arrives, `outbox_events.dispatched_at` populates, and `notification_deliveries`
  reaches `SENT` with a real provider ref.

**Horizon:** `laravel/horizon` is a declared dependency with **no `config/horizon.php` and no
provider** — ADR-0018 is entirely unimplemented, a bigger gap than "no worker container". Use
plain `queue:work` for launch (fewer public HTTP surfaces), record the deviation, add Horizon
in Phase 3. If enabled, confirm `/horizon`'s gate denies — its default permits only `local`.

### Lane B — Demo data → real data (~3–4 days)

**Mechanism: an operator-invoked purge command, not a migration change.** Rewriting the 17 data
migrations breaks CI's `browser-test` job and the suite (`CemeterySeedTest`,
`CemeteryPackageAvailabilityTest`, `GraveRecordSeedTest` assert on seeded rows because
`makam-testing` forbids domain factories). A deleting data-migration would run in CI too and is
the destructive pattern `AGENTS.md` §Database forbids.

Every fabricated dataset is already centralised behind
`app/Support/ExampleData/{CemeteryExampleData,VendorExampleData,VendorListingExampleData,ServiceOperationalExampleData}.php`,
and every row carries a marker (`GraveRecordSource::CONTOH`, the literal "Contoh" in
names/addresses). So:

- **B1 — `example-data:purge --force`** (1d). New
  `app/Console/Commands/PurgeExampleDataCommand.php`, reusing those generators rather than
  restating any data. Delete `grave_records` **first** (RESTRICT FK to `cemeteries`), then
  cemeteries by `CemeteryExampleData::slugs()`, then vendor listings/vendors/service areas, then
  null out the dummy pricing/photo backfills. Logic is verbatim what the migrations' own
  `down()` methods already do. **Do not purge** `feature_gates`/`feature_flags`,
  `launch_cities`, `faq_*`, `coa_accounts`, `products`/`service_definitions` (canonical
  catalogue master data), and critically **`notification_templates`** — the outbox listener does
  an `exists()` lookup on `notification_templates.outbox_event_name` and silently drops events
  if absent. Idempotent, `--force`-guarded, prints a row-count summary; reversible via the
  existing `CemeteryExampleDataSeeder`.
- **B2 — empty-state sweep** (1.5–2.5d). **This is the lane's real risk, not B1.** No journey
  has ever been walked against an empty catalogue. Check `CemeteryPublicQuery`, booking wizard
  Steps 1–3 cemetery selection, `MarketplaceCatalogQuery::findActiveByCode()`,
  `ProductDetail::firstActiveListing()`. Expect at least one hard failure, most likely in
  cemetery selection.
- **B3 — real data entry** (human, gated on H7). Via existing Filament resources
  (`CemeteryResource`, `VendorResource`, `ProductResource`). Source names/addresses/kelurahan
  from [Open Data Jakarta](https://data.jakarta.go.id/organization/dinas-pertamanan-dan-pemakaman)
  and [Portal Satu Data Indonesia](https://katalog.data.go.id/dataset/data-tempat-pemakaman-umum-tpu),
  attributed. **Two hard rules:** never populate deceased-person records (fictional *or* real —
  real ones are a UU PDP problem, fictional ones are the problem we're removing), and publish no
  price without a cited Perda and effective date. Where no verified tariff exists, show the
  honest "hubungi kami" state rather than a number.

### Lane C — Identity, legal, indexing (~2.5 days + legal latency)

- **C1 — settings-driven identity** (1d). `app/Support/CompanyInfo.php` and `ContactInfo.php`
  are `const`s, so `SiteSettings` **cannot** override them today. Convert to static methods
  reading `app(SettingsService::class)->setting(...)` — that service already implements
  config → env → DB → default precedence. Add `company_name`/`company_address` to
  `SiteSetting::KNOWN_KEYS` (`support_phone`/`whatsapp`/`email` already exist) and expose in
  `SiteSettingsForm`. Update `ContactInfo::summaryLine()` and the
  `WHATSAPP = self::PHONE` aliasing — don't leave a second hardcode.
- **C2 — de-DRAFT legal pages** (0.25d, gated on H3). `app/Livewire/Public/Legal/*`.
- **C3 — indexing control** (0.5d). Beta vhost sets
  `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet`. `public/robots.txt` is baked into
  one image shared by all vhosts, so it can't be per-environment — the header is authoritative
  and stronger; leave the file. Add a GATE to `ci/verify-infra.sh` asserting the header. Flip to
  indexable when legal text is final and data is real.
- **C4 — beta honesty banner** (0.5d). Persistent site-wide notice: beta status, **simulated
  payments**, what is and isn't operational. Given the payment decision, this is the single
  cheapest risk-reducer on the whole list — and the payment step needs its own explicit,
  unmissable treatment, not just the global banner.

### Lane D — Environment, backups, hardening (~6 days)

- **D1 — beta stack** (1.5d). Extend the existing `makam-nonprod` compose project; do not build
  a second stack. New `makam_beta` DB + user + secret file (owned `999:999`, mode `0400` — the
  compose long-syntax `uid/gid/mode` is confirmed non-functional on this host). Add to
  `postgres-init/01-create-databases.sh` **and** create manually on the running instance: the
  init script only runs on empty PGDATA, and that exact silent-skip already cost a full day.
  Extend the postgres healthcheck to assert `makam_beta` + `pg_trgm`. New `beta-web` on
  `127.0.0.1:8083`. **Raise memory limits** — the host is 8 vCPU / 31 GB, not ADR-0027's 2/4,
  and postgres currently sits at 66% of a 512m cap: postgres 2g, redis 512m
  (`maxmemory 384mb`, keep `noeviction` — evicting queue payloads silently loses jobs),
  beta-web 1g, worker 512m, scheduler 256m.
- **D2 — backups with a tested restore** (1.5–2d). `docs/operations/examples/backup-staging.sh`
  is production-quality and complete (pg_dump -Fc inside the container, `age` encryption, sha256
  sidecar, retention floor). It needs real `BACKUP_S3_*` values, an `age` keypair with **the
  private key stored off this host**, `makam_beta` targeting, and a systemd timer. **The
  deliverable is a documented restore into a scratch database, not a cron entry** — per
  `database-backup-and-recovery.md` §4, a backup is not valid until restored. Daily dumps mean
  up to 24h of real orders lost on volume failure; 4–6 hourly is ~1 extra hour of work.
- **D3 — public rate limits** (1d). Only three throttled routes exist today. Register limiters
  in `AppServiceProvider` (precedent: `PaymentServiceProvider::WEBHOOK_LIMITER`) and attach
  `throttle:` in `routes/web.php` to booking wizard writes, checkout, cart mutations, grave
  search, pre-need interest, visitation, abuse reports — all currently unthrottled and anonymous.
- **D4 — money-route hardening** (0.5d). `routes/web.php` ~489/~518 already carry
  `RequireRecentAuthentication`; the gaps are no `throttle:` and `EnforceMfaChallenge`
  no-opping for non-enrolled actors. Add throttles, and **enrol MFA on every beta admin
  account** as an operational step — with a handful of admins that is cheaper and stronger than
  making the middleware unconditional.
- **D5 — Redis `requirepass`** (0.5d). Snippet already exists. Defence-in-depth today, more
  important once Redis carries the queue.
- **D6 — credential hygiene** (0.25d). Treat the dev admin password in git history as
  permanently compromised; new accounts, users and secrets for beta, no history rewriting.
- **D7 — CSP report-only** (0.5d now, 0.5d later). Ship `Content-Security-Policy-Report-Only`
  first; Livewire 4 + Filament 5 will need allowances and enforcing blind breaks the admin panel.

### Lane E — Observability (~1.75 days)

Four pieces, nothing more — an unwatched dashboard is worse than none.

- **E1 — `/health/live` + `/health/ready`** (0.5d). Only `/up` exists;
  `ci-cd-and-release.md` §8 requires both. Ready checks Postgres `select 1`, Redis ping, config
  cached, no pending migrations. No secrets or version detail in the response (public host).
- **E2 — Sentry** (0.5d). `send_default_pii = false`, a `before_send` scrubber for NIK/KK/signed
  URLs per `observability-stack.md` §3/§5, tagged `environment=beta` + image digest. Attach the
  existing correlation ID (already flowing via `AssignCorrelationId`) as a tag so an error links
  to its outbox event and journal entry.
- **E3 — spine watchdog** (0.5d). **The highest-value alert available.** A scheduled command
  that errors when: any `outbox_events` row is undispatched >5 min (publisher died); any
  `notification_deliveries` row is `QUEUED` >15 min (worker died); `failed_jobs` grew. Emit via
  Sentry so routing is already solved. This covers the single most likely beta failure — the
  async spine dying silently while the UI looks fine.
- **E4 — uptime** (0.25d). UptimeRobot/Better Stack free tier, 5-min checks on `/up` and
  `/health/ready`. Route to H6. **One named on-call human, or this is theatre.**

---

## Phase 2 — Pre-launch gate (sequential, ~3 days, after A–E)

- **F1 — scripted manual UAT on the beta host** (1d). Not the test suite. A human walks:
  booking 9 steps → order reference → confirmation email received → tracking page → marketplace
  browse/cart/checkout → renewal → FAQ → admin order transition → vendor portal. Record evidence
  against `docs/testing/release-gates.md`. **2,754 passing tests prove none of this** — zero of
  60 release gates are checked and E2E is one homepage smoke spec.
- **F2 — fix the footer contrast bug** (0.5d). `resources/css/app.css` ~81–83: global
  `a { color: var(--mk-text-link) }` over `bg-primary-900` gives **1.68:1**, and
  `tests/browser/smoke.spec.ts` `.exclude('footer a')` to stay green. Fix with a footer-scoped
  colour, then delete the exclusion. ~2 hours to remove a known WCAG failure from a public
  bereavement site.
- **F3 — locale** (0.5–1d). `APP_LOCALE=id` is one env var, but with no `lang/` directory and
  `fallback_locale=en`, framework validation, pagination and password strings stay English.
  Add `lang/id/{validation,pagination,passwords}.php`. Filament 5 ships its own `id`
  translations, so panel chrome fixes itself. English validation errors mid-funeral-booking is
  a trust problem — keep this in scope.
- **F4 — ADR-0035 + written exceptions** (1d). Records the beta-on-non-production-host
  deviation from ADR-0021 (managed PostgreSQL/PITR) and ADR-0027 ("not accepted as
  production"), plus ADR-0018 (Horizon), ADR-0022 (Pulse/metrics) and ADR-0026 (performance
  evidence). Follow ADR-0031's structure — a documented, user-affirmed deviation with a stated
  reversal path. Add `docs/operations/runbooks/deploy-beta-vhost.md`. **Human review mandatory
  before merge** per `AGENTS.md` §Infrastructure-agent execution.

### Cutover to `makam.co.id`

Per the user's decision the app replaces the landing page at the apex. Sequence:
preserve the current landing page and its `makam-notify` Node service (back both up; decide
whether `/api/notify` survives) → new nginx vhost proxying the apex to `beta-web` → keep
`ci/verify-infra.sh` GATE I11 meaningful by updating what it asserts → certbot for the apex →
deploy → `migrate --force` → `example-data:purge --force` → real-data entry → smoke → watch.

**Rollback** is restoring the previous apex vhost and reloading nginx — keep the old config
file in place, not just in git.

---

## Phase 3 — Accepted risks, recorded in ADR-0035

Each needs a signed entry:

1. **Sandbox payments visible to real customers** (user decision). Mitigations: unmissable
   payment-step labelling, and a **daily reconciliation** of orders marked paid against actual
   settlement — with a named human who contacts any customer whose order is paid-without-money.
2. **No PITR.** Single self-managed Postgres container; daily dumps = up to 24h of real orders
   lost on volume failure. Explicitly violates ADR-0021.
3. **Single host, no HA.** Host loss = total outage, RTO in hours.
4. **No capacity evidence.** ADR-0027 and `AGENTS.md` line 53 both require it pre-production.
5. **Booking Step 7 document upload absent** (MVP §2), per D1.
6. **UU PDP compliance is minimum-viable, not audited** — no DPIA, no formal consent register,
   no tested breach drill. D1 (collecting no KTP/KK/death certificates) removes more risk than
   any policy document; note that controller obligations attach **on collection, not on
   payment**, so "no real money" does not shrink the legal surface much.
7. **60 release gates unverified.**
8. **Postgres and Redis share a host with the public dev environment** (ADR-0031), which
   already receives automated `.env`/`.git` probes.

---

## Verification

Nothing below is satisfied by the existing test suite; each needs executing on the beta host.

| What | How | Pass condition |
|---|---|---|
| Async spine live | Submit a booking; watch `outbox_events` and `notification_deliveries` | `dispatched_at` populates within 60s; delivery reaches `SENT` with a real provider ref |
| Email actually delivers | Book with a real external address (Gmail) | Message arrives **in inbox, not spam**, with correct Indonesian copy |
| Spine watchdog fires | Stop `beta-worker`, submit a booking, wait 15 min | Sentry alert reaches the on-call phone |
| No fake data public | `example-data:purge --force`, then crawl every public route | Zero occurrences of "Contoh", zero `GraveRecordSource::CONTOH` rows, no dummy prices |
| Empty states honest | Walk all journeys against the purged catalogue | Documented empty state everywhere; no 500s, no dead ends |
| Backup is real | Restore the latest encrypted dump into a scratch DB | Row counts match; app boots against the restore |
| Not indexed | `curl -I https://makam.co.id/` | `X-Robots-Tag: noindex...` present |
| Payment labelling | Walk booking to Step 8 as a guest | Simulated-payment warning unmissable before any redirect |
| a11y | `npx playwright test` with the footer exclusion removed | axe passes, no contrast violation |
| Rate limits | Hammer booking-wizard writes and grave search | 429 after the configured threshold |
| Health | `curl /health/ready` with postgres stopped | Non-200 |
| Locale | Submit invalid form data | Validation errors in Indonesian |

**Full-suite regression runs in CI, never on this host** (PHP 8.3.6 locally vs `composer.lock`
requiring ≥8.5). Deploy only the digest-pinned GHCR image CI built from
`origin/docs/design-system-and-planning` — **the local checkout is 25 commits behind origin.**

---

## Execution notes

Per `AGENTS.md` §Development methodology this is Superpowers SDD work: a plan doc committed at
`docs/superpowers/plans/`, worktree isolation under `.worktrees/`, one PR per lane against
`docs/design-system-and-planning`, task-scoped review then whole-branch review.

**Lanes C, D and F4 touch privacy, security, DNS and production-affecting surfaces — human
review is mandatory before merge, not optional.** So is the apex cutover.

Lanes A, B, D and E are genuinely independent and can run concurrently. Lane C's code is
independent but its *values* gate on H1–H3. **A2 and A3 must land in the same PR.**
