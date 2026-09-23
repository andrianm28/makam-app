# FFI Full Visual Clone — Design

**22 September 2026.** Supersedes [`2026-09-22-ffi-design-language-spec.md`](2026-09-22-ffi-design-language-spec.md)
in full. Decided by [ADR-0043](../../adr/0043-ffi-full-visual-clone-supersedes-system-layer-only.md),
which supersedes [ADR-0042](../../adr/0042-ffi-system-layer-is-the-design-language-source.md)
and ADR-0041's palette and typography. Produced by a grill session (`specflow:grilling`
+ `specflow:domain-modeling`) run inside `superpowers:brainstorming`'s
architectural path, per `AGENTS.md`'s Development methodology.

Reference source, read directly (not from notes) for every fact cited
below: `~/fundforindonesia.org` (`/home/ubuntu/fundforindonesia.org`), a
running Next.js 14 app whose `package.json` name is `"kitabisa-clone"` — an
unrebranded clone of the real Indonesian crowdfunding platform Kitabisa. The
owner was told this directly and, informed of it, asked for the full visual
identity and page structure regardless.

## 1. Scope

**In:**
- Public surface: homepage, cemetery directory, booking wizard, renewal,
  marketplace, FAQ, akun, help centre.
- `resources/css/tokens.css` values (names unchanged), typography, the
  `x-mk.*` component library, a new bottom-navigation component.

**Out:**
- Filament admin/operator/vendor panels. Own palette gate
  (`design:verify-filament-palette`), not what the owner is evaluating when
  judging "tampilan".
- Step structure and flow of the booking wizard, renewal, and marketplace.
  FFI has no multi-step commercial flow comparable to any of these — it is
  a single-domain donation site. Only their visual system (palette,
  typography, card/radius/shadow, spacing) changes; step count, field
  rules, and validation are untouched.
- Copy voice. ADR-0041 pages 01–03 (anti-hard-selling, "Menemani Keluarga,
  Menjaga Kenangan") stay authoritative. No FFI donation-site vocabulary —
  no "Donasi", "Galang Dana", "Zakat" — anywhere in Makam.
- `AGENTS.md`'s four-primary-service rule. Not amended by this design.

## 2. Palette and typography

Token **names** in `tokens.css` are unchanged; **values** become FFI's,
read directly from `tailwind.config.ts`:

| Token role (name unchanged) | New value |
|---|---|
| primary | `#0073E6` |
| primary, dark | `#005BB5` |
| accent | `#FF6B35` |
| success | `#00C853` |
| warning | `#FFB300` |
| danger | `#D50000` |
| background | `#FFFFFF` |
| background, secondary | `#F5F5F5` |
| text | `#212121` |
| text, secondary | `#757575` |
| border | `#E0E0E0` |

Typeface: Plus Jakarta Sans → **Inter**, self-hosted (no external `<link>`,
matching how the current typeface is already loaded).

Radius (`--radius-md` 8px / `--radius-lg` 12px / `--radius-xl` 16px) and the
shadow scale need **no value change** — confirmed identical to FFI's own
`borderRadius.sm/md/lg` (8/12/16px) and `boxShadow.card` (`0 2px 8px
rgba(0,0,0,.08)`) / `boxShadow.elevated` (`0 4px 16px rgba(0,0,0,.12)`).
Only the mapping from Makam's five-tier scale to FFI's two named tiers
needs stating: `card` → `--shadow-sm`/`--shadow-md` range (rest state),
`elevated` → `--shadow-lg` (hover/active state), matching how `emphasis` on
`<x-mk.card>` already resolves rest/hover pairs.

`--radius-full`'s pill-button prohibition is lifted (ADR-0043). Search
input shape is explicitly **not** part of this lift — it stays Makam's
existing input geometry unless a later, separate decision asks for FFI's
pill search bar specifically.

## 3. Component library

- **`<x-mk.skeleton>` (new).** Props: `shape` (`text`|`card`|`media`|
  `section`, default `text`), `lines` (int, default 3, `text` only),
  `count` (int, default 1), `section-rhythm` (bool, `section` only,
  mandatory true for that shape), `announce` (string, default
  "Memuat…"). Always emits `aria-busy="true"` and one `sr-only` node with
  `announce`. Colours are always `--mk-skeleton-base`/`-sheen` — no
  external colour prop. Respects `prefers-reduced-motion` (denyed → no
  pulse animation, base colour only, matching the existing shadow
  reduced-motion convention).
- **`<x-mk.bottom-nav>` (new).** Five tabs: Beranda (`/`), Pemesanan
  (`/pemesanan-makam`), Perpanjangan (`/perpanjangan`), Akun (`/akun`),
  Bantuan (`/bantuan`). Visible only below `lg`. Active tab marked by
  colour **and** shape (never colour alone — `design-system.md` §7). No
  animation library; active-state transition is colour/opacity only, using
  the existing `duration-fast` utility. `aria-current="page"` on the active
  tab; wrapped in `<nav aria-label="Navigasi utama">`. Must not obscure the
  booking wizard's sticky CTA — the plan's implementation task must test
  both together and decide which yields, not treat this as a detail.
- **`<x-mk.card>` `emphasis` prop, `<x-mk.button>` `variant` prop** — APIs
  unchanged, only the values they resolve to change.

## 4. Homepage restructure

The full current section order, read directly from
`resources/views/livewire/public/home-page.blade.php`, top to bottom:

1. Urgent-mode fallback banner (server-truthful, `ModeResolver::urgentMode()`)
2. Hero — `<x-mk.hero>`, one `<h1>`, one primary CTA (§2.3: the component
   structurally supports no more)
3. Plot-availability preview (real plot data; renders nothing on failure)
4. Services — 4 cards (`services-heading`)
5. How it works (`how-it-works-heading`)
6. Featured cemeteries (`featured-cemeteries-heading`)
7. Trust/safety (`trust-heading`)
8. Family warmth (`family-warmth-heading`)
9. FAQ highlights (`faq-highlights-heading`)
10. Customer-service CTA (`cs-cta-heading`)

FFI's homepage, read directly from `src/app/page.tsx`'s imports and JSX
order: `HeroBanner` → `QuickActionTiles` → `UrgentCampaigns` →
`CampaignGrid` (×2, same component instantiated twice with different
props — not two distinct components) → `PrayerWall`.

### 4.1 Section mapping

| # | New section | Source | Content |
|---|---|---|---|
| 1 | Urgent-mode banner | unchanged | unchanged |
| 2 | Hero | FFI `HeroBanner`, restyled | Unchanged heading/CTA content, decided separately (§6). Single CTA, per §2.3 — unchanged. |
| — | Secondary CTA links (Perpanjang Makam, Layanan Pemakaman, Wakaf Tanah) | **existing codebase precedent**, not invented | Rendered as plain text links near the services section (§4.2), matching this file's own documented precedent for the prior "Lihat TPU & TPS" secondary CTA — never inside `<x-mk.hero>`, which has no slot for them. |
| 3 | Plot-availability preview | kept, restyled | No FFI equivalent; functional Makam content, out of Q2's scope (which covered only FFI's crowdfunding-specific sections). Position unchanged relative to hero. |
| 4 | Services, 4 cards | FFI `QuickActionTiles`, restyled | **Count stays 4** (`AGENTS.md`). FFI's tile shape, icon treatment, spacing adopted; count does not. |
| 5 | Urgent TPU/TPS | FFI `UrgentCampaigns`, restyled | TPU/TPS with urgent availability, horizontal scroll — same interaction pattern, Makam content |
| 6 | Newest TPU/TPS | FFI `CampaignGrid` (first instance), restyled | Newest published TPU/TPS |
| 7 | Featured/verified TPU/TPS | FFI `CampaignGrid` (second instance), restyled | This is also where the PRD trust element moves above the fold (§7) — "lokasi terverifikasi" badge (already-decided definition: active capability profile, evidence present), "harga transparan" |
| 8 | Family warmth | kept, restyled | No FFI equivalent; explicitly protected by ADR-0043's copy-voice carve-out (ADR-0041 pages 01–03 untouched) |
| 9 | FAQ highlights | kept, restyled | No FFI equivalent; existing content |
| 10 | Customer-service CTA | kept, restyled | Unchanged content |
| — | `PrayerWall` | **removed**, no mapping | No honest equivalent in Makam's domain |

Sections 6/7 replace what were previously "how it works" (§4 old) and
"trust/safety" (§6 old) in position; their content (how-it-works
explanation, trust/safety copy) is not deleted — it is redistributed into
the featured/verified section (§7 above) and the FAQ highlights section,
decided at plan-writing time, not here, since it is a content-fitting
detail, not a structural one.

### 4.2 Secondary CTA placement, precisely

Matches the exact precedent already in `home-page.blade.php`'s own
comment block (quoted verbatim): *"the prior secondary 'Lihat TPU & TPS'
button moved into Section 3's card grid area as a plain text link below
the cards ... rather than competing with the hero's one sanctioned primary
action."* The three PRD-required secondary destinations (Perpanjang Makam,
Layanan Pemakaman, Wakaf Tanah) follow the same placement: text links
below the services card grid (new position 4), not inside the hero.

## 5. Sequencing — three PRs

1. **Foundation.** Token values, typography, full `verify-contrast.py`
   rebase against the new palette. No Blade file touched. GATE 1 is the
   acceptance bar.
2. **Components.** `<x-mk.skeleton>`, `<x-mk.bottom-nav>`, component-level
   visual updates (`card`, `button`, `icon-medallion`, etc.) to the values
   Stage 1 established.
3. **Homepage restructure.** Section reorder per §4.1, secondary-CTA
   placement per §4.2, trust-element repositioning. Other public pages
   (wizard, renewal, marketplace, FAQ, akun) get Stage 1/2's visual system
   applied to their existing structure — no structural change, confirmed
   in scope (§1).

Each stage is its own PR, its own review, per `AGENTS.md`'s Development
methodology (task-scoped review, then whole-branch review).

## 6. Explicitly not decided here

- The hero CTA label mismatch ("Pesan Makam" vs PRD's "Cari Makam") — a
  wording decision, independent of this visual work, left for whoever owns
  that PRD follow-up.
- Whether `information-architecture.md` §3's "nine normative sections"
  count gets updated to reflect Plot-availability-preview and Family-warmth
  formally, or stays as an acknowledged, documented addition the way it is
  today. Not blocking; noted for whoever next touches that document.
- Exact icon/illustration set for the four service cards under FFI's tile
  treatment — an asset decision for the implementation task, not a
  structural one.

## 7. PRD compliance

| PRD requirement | Status after this design |
|---|---|
| 4 kartu layanan (`AGENTS.md` §Mandatory MVP UX) | Unchanged — count preserved, only visual treatment changes |
| Hero, satu CTA utama | Structurally unchanged (`<x-mk.hero>` still supports exactly one); label wording is a separate, untouched decision (§6) |
| CTA sekunder Perpanjang Makam / Layanan Pemakaman / Wakaf Tanah di hero | Closed in Stage 3, placed per §4.2's existing precedent, not inside the hero |
| Elemen kepercayaan (lokasi terverifikasi, harga transparan) di area pertama | Closed in Stage 3 — moved into position 7, ahead of where trust content sat before |
| Kontak bantuan | Unchanged, already present as position 10 |
| Bahasa Indonesia saja | Unchanged — no FFI copy imported, visual system only |
| Sepuluh state wajib (`design-system.md` §6) | Preserved explicitly on every touched screen; not a Stage 3 casualty of restructuring |
| WCAG AA kontras | Re-verified in Stage 1 against the new palette values before any later stage begins |

## 8. Verification per stage

- Stage 1: `python3 docs/design/verify-contrast.py` (or its current
  invocation) green against the rebased pairs; `ci/verify-docs.sh` GATE 1.
- Stage 2: GATE 2 (no hardcoded values outside `tokens.css`), GATE 3 (no
  arbitrary Tailwind values), GATE 11 (no raw `z-index`), GATE 12 (focus
  suppression has a replacement) — all against the new components.
- Stage 3: `blade:verify-content-survival`, ten-mandatory-states check per
  touched screen, PRD compliance table (§7) re-confirmed against the
  merged state, not just the plan.
- All three stages: `design:verify-filament-palette` stays green
  unmodified, proving Filament truly wasn't touched.

## Self-review

- **Placeholder scan:** no TBD/TODO. §6 lists what is deliberately
  undecided, each with an owner or a reason, not a gap.
- **Internal consistency:** §4.1's section list and §7's PRD table agree on
  where the trust element and secondary CTAs land (both say "Stage 3,
  position 7 / position 4"). §2's "no value change" claim for radius/shadow
  is checked against the same source table as §4's FFI section list — both
  read from the live FFI repo in the same validation pass, not from two
  different points in the conversation.
- **Scope check:** single spec, three-stage plan, matches `writing-plans`'
  expectation of one plan per coherent unit of work — three stages, three
  plans, not one.
- **Ambiguity check:** §4.2 pins the secondary-CTA placement to an exact,
  quoted precedent rather than leaving "near the cards" open to
  interpretation by whoever writes the implementation task.
