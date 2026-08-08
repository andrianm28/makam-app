# Tasks — Pre-Need Contracting

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Implement interest/consultation only flow. _Requirements: 1_
- [ ] Model proposal and non-binding quote. _Requirements: 3_
- [ ] Prepare gated agreement/payment schedule interfaces. _Requirements: 2, 4, 6_
- [ ] Bind agreement acceptance to the exact agreement and quote versions, behind gate. _Requirements: 5_
- [ ] Issue a certificate only when eligibility rules are satisfied, behind gate. _Requirements: 7_
- [ ] Link future activation/claim to a new At-Need FuneralCase without losing original contract history, behind gate. _Requirements: 8_
- [ ] Add hard tests proving no payment while gate closed. _Requirements: 1_
- [ ] Record legal/accounting open decisions. _Requirements: 2_
- [ ] Do not implement paid states until stakeholder approval. _Requirements: 2_

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

### Gate-closed UI contract (the primary design requirement)

While `G-LEGAL-01` is closed, the UI must be **unambiguous that no payment is being taken**. `AGENTS.md`: *"Paid Pre-Need is impossible while legal gate is closed; register interest instead."*

| Element | Primitive | Tokens |
|---|---|---|
| Pre-Need mode banner | `<x-mk.alert intent=info>` §3.8 + §6.9 | `--mk-intent-info-*`; `PreNeedMode = INTEREST_ONLY` read from the **server** — a front-end flag is insufficient |
| Step 8 while gated | explicit no-payment statement | `booking-wizard-fields.md` §Branching: **Step 8 is never removed**; it states plainly that no payment is accepted |
| Interest form | `<x-mk.field>` §3.2 | `--mk-control-h-md`, `--text-base` (16 px floor), `--mk-field-gap` |
| Consultation request | `<x-mk.button variant=primary>` §3.1 | `--color-primary-600`; label must not imply purchase |
| Non-binding quote (AC2) | `<x-mk.card>` + `<x-mk.badge intent=neutral>` | **`neutral`, never `success`.** A non-binding proposal styled as success reads as a confirmed purchase |
| Agreement terms (AC4) | prose §1.4 | `--container-prose`, `--text-base`; price guarantee, cancellation/refund, transferability, term, responsible entity — all legible, none in fine print |
| Agreement acceptance | `<x-mk.field>` checkbox + `<x-mk.modal>` §3.4 | bound to exact version (AC5); version badge visible at the moment of acceptance |
| Payment schedule (gated) | `<x-mk.table>` §3.5 | **interface only**; must not render a payable state while the gate is closed |

### Status → intent (normative)

Register in the shared `StatusIntent` helper (design-system.md §3.7). **Every paid state must be unreachable while the gate is closed** — and unreachable in the UI, not merely guarded server-side.

`INTEREST` neutral · `CONSULTING` info · `PROPOSED` info · `RESERVED` pending · `CONTRACT_PENDING` pending · `ACTIVE_PAYMENT` pending · `SETTLED` success · `CERTIFIED` success · `ACTIVATED` info · `CANCELLED` neutral · `DEFAULTED` danger

While gated, only `INTEREST` and `CONSULTING` are renderable.

### Required UI states

All ten states apply — design-system.md **§6**. UI appears at **PUB-012** (booking Step 3 → `PRE_NEED`) and a Pre-Need interest view.

> **Gap:** the Pre-Need interest/consultation surface has **no screen-inventory ID.** Add it to [`screen-inventory.md`](../../../docs/product/screen-inventory.md).

| Concern | State notes |
|---|---|
| loading | §6.1 |
| empty | §6.2 — no proposal yet ("Belum ada penawaran" + consultation CTA) |
| validation | §6.3 inline + summary; never clear entered data |
| **authorization / gate closed** | §6.4 — **explanatory state, never a 404 and never a dead link.** State that paid Pre-Need is not yet available and that interest can be registered |
| provider unavailable | §6.5 — notification failure does not change business state |
| duplicate/retry-safe | §6.6 — repeated interest submission must not create two records |
| pending | §6.7 — awaiting consultation contact; `pending` never styled as success |
| success | §6.8 — **quiet, and explicitly "minat terdaftar", not "pembelian berhasil"** |
| support | §6.10 — Pre-Need buyers need a human route |
| responsive | §4.3 |

### Tone constraint

design-system.md §2.3: no urgency manufacturing. Pre-Need is a long-horizon decision; countdowns, scarcity claims, and "harga naik" pressure are prohibited.

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Read `PreNeedMode` from the **server**; render the `intent=info` gate banner (§6.9).
- [ ] Keep Step 8 present with an explicit no-payment statement; never remove the step.
- [ ] Render non-binding quotes as `neutral`, never `success`.
- [ ] Ensure no paid-state badge, payment button, or payable schedule is renderable while `G-LEGAL-01` is closed.
- [ ] Present agreement terms at prose measure; no fine print for cancellation/refund/transferability.
- [ ] Add the Pre-Need interest surface to `screen-inventory.md` with its states.
- [ ] Implement all ten required states per the table above.
