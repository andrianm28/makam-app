# Tasks — Admin Operations

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [x] Build admin resources for directory/catalog/vendor/FAQ. _Requirements: 1, 2, 3, 6_ — shipped in the master-data batches (lanes `cemetery-admin-resource`, `nav-wiring`, `admin-fixwave`; merged as PRs #41/#43/#44, 13 Aug 2026): `app/Filament/Admin/Resources/CemeteryResource.php`, `ProductResource` (`app/Filament/Admin/Resources/ProductResource/ProductResource.php`), `ServiceDefinitionResource.php`, and `FaqArticles/FaqArticleResource.php`, all wired into the admin navigation via `AdminPanelProvider`'s `discoverResources()`. The vendor-facing resources live in the separate `/vendor` panel (`app/Filament/Vendor/Resources/`) per the vendor-portal split; "vendor" here means the admin's catalogue/vendor-master-data surface, which is covered by the Product/ServiceDefinition resources.
- [ ] Implement PIC and communication log. _Requirements: 4_
- [ ] Implement guarded quote/payment actions. _Requirements: 9_
- [ ] Implement transaction reference views. _Requirements: 5_
- [ ] Implement manual payout and external renewal proof forms. _Requirements: 5_
- [ ] Implement period reports and export authorization. _Requirements: 7, 10_
- [ ] Add audit events and security tests. _Requirements: 8_
- [ ] Disable unsafe bulk state changes. _Requirements: 9_

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

This spec covers 11 Filament screens (ADM-001…100). The admin panel **inherits the same tokens as the public site** (design-system.md §8.3) so a `DIBAYAR` badge is identical for the customer and the admin. Do not restyle the panel.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Panel palette | Filament theme §8.3 | derived from `--color-primary-*`, `--color-success-*`, `--color-warning-*`, `--color-danger-*`, `--color-info-*`, `--color-neutral-*` |
| Tables | `<x-mk.table>` §3.5 / Filament tables | `--mk-table-hover`, `--mk-table-stripe`, header `--color-neutral-50` + `--tracking-wide` |
| Dense row actions | `<x-mk.button size=sm>` §3.1 | `--mk-control-h-sm` (36 px) — **the only place below the 44 px floor**, permitted on pointer devices only |
| Status badges | `<x-mk.badge>` §3.6 + §3.7 | via `StatusIntent`, shared with the public site |
| Forms | `<x-mk.field>` §3.2 / Filament forms | `--mk-border-interactive`, `--text-base` |
| **Sensitive-action confirmation** | `<x-mk.modal>` §3.4 | `danger` confirm button; consequence stated in the body; **typed reason where mandated** (`DITOLAK` reason is mandatory); **never default-focus the destructive button** |
| Exception queues (AC11) | `<x-mk.table>` + badges | failed payment `danger` · missing operator response `pending` · vendor delay `pending` · unmatched renewal `pending` |
| Bulk export | `<x-mk.button variant=secondary>` §3.5 | **never `primary`**, never adjacent to a benign action — privileged, requires recent re-authentication |
| Currency / totals | table §3.5 | `text-right tabular-nums`, `--font-mono`, total `--font-weight-bold` |
| Reports | `<x-mk.table>` §3.5 | financial totals must reconcile to journal references, per this spec's `design.md` |

### Order lifecycle → intent (normative)

From design-system.md §3.7 — resolve through the single `StatusIntent` helper, shared with the public site. Never `match` on the enum inside a Filament closure.

`MASUK` neutral · `DIVERIFIKASI` info · `MENUNGGU_KETERSEDIAAN` pending · `PENAWARAN_TERKIRIM` info · `DISETUJUI_PEMESAN` info · `MENUNGGU_PEMBAYARAN` pending · `MENUNGGU_VERIFIKASI_PEMBAYARAN` **pending (never success)** · `DIBAYAR` success · `DIPROSES` info · `SELESAI` success · `DITOLAK` danger · `DIBATALKAN` neutral · `KEDALUWARSA` neutral

### Required UI states

All ten states apply to every admin screen — design-system.md **§6**. The original task list specified none; this table closes that gap.

| Screen group | State notes |
|---|---|
| ADM-001 dashboard | loading §6.1 skeleton tiles · empty §6.2 per widget (hide rather than render an empty shell) |
| ADM-010…030 master data | loading · empty ("Belum ada data" + create action) · validation §6.3 inline + summary · success §6.8 quiet |
| ADM-040…060 orders | loading · empty · filter no-result §6.2 with `Reset filter` · status badges via `StatusIntent` |
| ADM-070 payment / manual verification | `pending` §6.7 for `MENUNGGU_VERIFIKASI_PEMBAYARAN` — **never render as success** · failure §6.5 · **AC9: UI must not permit bypassing payment/state invariants** |
| ADM-080 FAQ CMS | draft badge `neutral`; publish `pending` → `success` |
| ADM-090 reports | loading (long queries) · empty · export `pending` |
| ADM-100 audit | loading · empty · read-only |
| authorization | §6.4 — AC10 scope violations return an explanatory state and must not reveal whether the out-of-scope record exists |
| duplicate/retry-safe | §6.6 — repeated action submit must be idempotent; AC9 bulk actions must not bypass guards |
| support | §6.10 — internal escalation/runbook link |
| responsive | §4.3 — tables reflow to stacked cards below `--breakpoint-md`; admin is used on tablets in the field |

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Confirm the Filament admin panel inherits tokens (§8.3) and keep the PHP colour array generated from `tokens.css`, never hand-edited (OQ-09).
- [ ] Resolve every status through the shared `StatusIntent` helper (§3.7).
- [ ] Implement all ten required states for each ADM screen group per the table above.
- [ ] Build sensitive-action confirmations per §3.4: consequence stated, reason captured, destructive button not default-focused.
- [ ] Render bulk export as `secondary`, separated from benign actions.
- [ ] Verify accessibility (§7); `size=sm` controls only on pointer devices, never on touch layouts.
