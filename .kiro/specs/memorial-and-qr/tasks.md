# Tasks — Memorial and QR

- [ ] Define consent/authority workflow and privacy modes.
- [ ] Build private memorial draft and editor roles.
- [ ] Build public projection and opaque QR token.
- [ ] Add moderation/report/unpublish workflows.
- [ ] Add privacy, token revocation, enumeration, and cross-family tests.

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

This is the most privacy-sensitive surface in the product and the only one where **the current privacy mode must be visible at all times**. A family editing what they believe is a private memorial, which is in fact public, is a serious harm.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| **Privacy mode indicator** | `<x-mk.badge>` §3.6 | **persistent, never collapsed.** private `neutral` · family-only `neutral` · unlisted `pending` · **public `info`** (an intentional state, not a success) |
| Privacy mode selector | `<x-mk.field>` radio §3.2 | 20 px control in 44 px rows; each option states its consequence in plain Indonesian |
| Consent/authority capture (AC1) | `<x-mk.modal>` §3.4 | evidence bound to actor; confirm is deliberate, not default-focused |
| Draft memorial editor | `<x-mk.field>` + `<x-mk.card>` §3.2/§3.3 | `--container-prose` measure; `--text-base`; generous `--mk-field-gap` |
| Publish action | `<x-mk.modal>` §3.4 | **consequence stated explicitly** ("dapat dilihat siapa saja"); this is the highest-stakes confirmation in the product |
| Unpublish (AC5) | `<x-mk.button variant=danger>` §3.1 | **immediate**, one step, always reachable by an authorized moderator |
| Media | upload states §6.7 | `pending` while scanning; **never previewable before scan acceptance** |
| QR token display | `<x-mk.card>` + `--font-mono` | AC4 — opaque, revocable; **never embed a restricted identifier** |
| Token rotate/revoke | `<x-mk.modal>` §3.4 | `danger` confirm; consequence stated (existing printed QR stops working) |
| Moderation queue | `<x-mk.table>` §3.5 | inherits admin-panel tokens §8.3 |
| Report abuse (AC6) | `<x-mk.button variant=secondary>` §3.1 | reachable from the public projection |

### Public projection is allowlist-driven (AC3)

The public read model renders **only** allowlisted fields. There is no "hide with CSS" pattern here — a field that must not be public is not rendered at all. Never rely on `display: none`, `sr-only`, or a collapsed accordion to protect restricted data.

### Required UI states

All ten states apply — design-system.md **§6**.

> **Gap:** this spec has **no screen-inventory ID** — consistent with `mvp-scope.md` §8, which excludes Memorial/QR from MVP. If `G-MEM-01` opens, add screens to [`screen-inventory.md`](../../../docs/product/screen-inventory.md) first.

| Concern | State notes |
|---|---|
| loading | §6.1 skeleton; quiet — this is a memorial page, not a feed |
| empty | §6.2 — new memorial with no content yet; guide the family gently, no empty-state jokes or illustrations |
| validation | §6.3 inline + summary; **never clear entered content** — a family may have written a long tribute |
| **authorization** | §6.4 — AC8/negative criteria: cross-family access must return an explanatory state that **does not reveal whether the memorial exists**. Same for a revoked QR token |
| **provider unavailable** | §6.5 — media scanner outage → `pending`, **never `accepted`** (fail-closed) |
| duplicate/retry-safe | §6.6 — repeated publish must be idempotent; token rotation must not orphan a memorial |
| **pending** | §6.7 — awaiting moderation (AC6), awaiting media scan, awaiting consent evidence. **Never styled as published** |
| success | §6.8 — **quiet.** No celebration on publishing a memorial |
| support | §6.10 — moderation appeal and abuse-report routes must be reachable |
| responsive | §4.3 — public memorial is most often opened by scanning a QR at the graveside on a phone in daylight: 320 px, high contrast, large tap targets |

### Tone constraint

design-system.md §2.3 applies at maximum strength: no celebration, no engagement mechanics, no view counters, no "share" nudges, no stock grief imagery. Quiet and respectful.

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Make the privacy-mode badge persistent on every memorial view, public and private.
- [ ] Build publish/unpublish/token-revoke confirmations with the consequence stated explicitly (§3.4).
- [ ] Render the public projection from an allowlist; never hide restricted fields with CSS.
- [ ] Ensure authorization denial and revoked tokens do not reveal existence (§6.4).
- [ ] Keep media fail-closed: `pending` until scan acceptance, no preview before (§6.7).
- [ ] Add screens to `screen-inventory.md` before building, if `G-MEM-01` opens.
- [ ] Implement all ten required states per the table above.
