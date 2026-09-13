# Order Lifecycle

## 1. Canonical states

```text
MASUK
-> DIVERIFIKASI
-> MENUNGGU_KETERSEDIAAN
-> PENAWARAN_TERKIRIM
-> DISETUJUI_PEMESAN
-> MENUNGGU_PEMBAYARAN
-> MENUNGGU_VERIFIKASI_PEMBAYARAN (manual path only)
-> DIBAYAR                        (confirm-first flow)
-> DIBAYAR_MENUNGGU_KONFIRMASI    (pay-first flow)
-> DIKONFIRMASI                   (pay-first flow)
-> DIPROSES
-> SELESAI
```

`MENUNGGU_VERIFIKASI_PEMBAYARAN` sits on the **manual** path only, between
`MENUNGGU_PEMBAYARAN` and `DIBAYAR`: a customer who chooses manual coordination
submits payment evidence and waits for a finance verification decision. It is
never on the online path.

A **rejected** manual verification is not an order transition at all — it is a
`PaymentVerificationStatus` change, so the order stays where it is and the
customer resubmits evidence. That is how "no transition backward" and the
customer's need to retry are satisfied together.

`DIBAYAR_MENUNGGU_KONFIRMASI` and `DIKONFIRMASI` sit on the **pay-first**
path only, added 13 Sep 2026 when the owner reversed the order of money and
confirmation: the customer pays in full and an admin decides afterwards
(`docs/superpowers/plans/2026-09-13-bayar-penuh-di-muka-online-saja.md`). An
order on that path goes `MENUNGGU_PEMBAYARAN -> DIBAYAR_MENUNGGU_KONFIRMASI`
and then either `-> DIKONFIRMASI -> DIPROSES` or `-> DITOLAK_SETELAH_BAYAR`.

`DIBAYAR` is **not** on that path, and keeps its exact previous meaning: the
settled state of the confirm-first flow, where an operator accepted the order
before any money moved. The two are separate states on purpose — see §3's
"nothing terminal after `DIBAYAR`" rule, which the separation is what
preserves.

Terminal branches:

```text
DITOLAK
DITOLAK_SETELAH_BAYAR
DIBATALKAN
KEDALUWARSA
```

## 2. Transition matrix

| From | To | Actor/trigger | Guard | Required audit data |
|---|---|---|---|---|
| Draft submission | MASUK | Customer | Required data complete | actor, draft, timestamp |
| MASUK | DIVERIFIKASI | Admin | Identity/data review completed | verifier, note |
| DIVERIFIKASI | MENUNGGU_KETERSEDIAAN | Admin | PIC assigned | PIC, cemetery |
| DIVERIFIKASI | PENAWARAN_TERKIRIM | Admin/operator | **Conditional edge, not unconditional like every other row in this table.** Reachable only via `Actions\IssueQuoteFromReservedPlot`, which requires an active plot reservation on the order AND that the reservation's own cemetery is granular-tier. Skips the manual availability step because the specific plot is already held. | actor, quote version |
| MENUNGGU_KETERSEDIAAN | PENAWARAN_TERKIRIM | Admin | Availability confirmed manually | source, operator/admin note, quote version |
| PENAWARAN_TERKIRIM | DISETUJUI_PEMESAN | Customer | Quote not expired | customer, quote version |
| DISETUJUI_PEMESAN | MENUNGGU_PEMBAYARAN | Admin/system | Payment gate active and admin opens payment | actor, gate evidence |
| MENUNGGU_PEMBAYARAN | MENUNGGU_VERIFIKASI_PEMBAYARAN | Customer | Manual coordination chosen; evidence submitted | actor, verification reference |
| MENUNGGU_PEMBAYARAN | DIBAYAR | Valid webhook | Signature, amount, merchant, idempotency valid | provider transaction, journal ref |
| MENUNGGU_VERIFIKASI_PEMBAYARAN | DIBAYAR | Finance/admin | Approved manual verification; amount equals quote total | actor, verification reference |
| MENUNGGU_PEMBAYARAN | DIBAYAR_MENUNGGU_KONFIRMASI | Valid webhook | Pay-first path. Signature, amount, merchant, idempotency valid — the same guard as the `DIBAYAR` row above; only the landing state differs. Never reachable from an admin panel button: an order arrives here because money did | provider transaction, journal ref |
| DIBAYAR_MENUNGGU_KONFIRMASI | DIKONFIRMASI | Finance/admin | Admin accepts the paid order. Fresh re-authentication required (`confirm_paid_order` is a money transition) | actor, note |
| DIBAYAR_MENUNGGU_KONFIRMASI | DITOLAK_SETELAH_BAYAR | Finance/admin | **A `refund_obligations` row for this order must already exist in the same transaction**, or `Actions\RecordOrderStatusChange` refuses the write. Reason mandatory. Fresh re-authentication required. Reached only through `Actions\RefusePaidOrder` | actor, reason, refund obligation |
| DIBAYAR | DIPROSES | Admin/vendor/operator | Fulfilment started | actor, work reference |
| DIKONFIRMASI | DIPROSES | Admin/vendor/operator | Fulfilment started — the pay-first counterpart of the `DIBAYAR -> DIPROSES` row above | actor, work reference |
| DIPROSES | SELESAI | Admin | Completion evidence/confirmation | actor, evidence, note |

## 3. Branch rules

- `DITOLAK`: admin/operator availability input or verification decision; reason mandatory. Reachable only from `MASUK`, `DIVERIFIKASI`, and `MENUNGGU_KETERSEDIAAN` — never after a quote has been sent.
- `DIBATALKAN`: customer cancellation while policy permits; financial consequences are TBD. Reachable from `MASUK`, `DIVERIFIKASI`, `MENUNGGU_KETERSEDIAAN`, `PENAWARAN_TERKIRIM`, `DISETUJUI_PEMESAN`, `MENUNGGU_PEMBAYARAN`, and `MENUNGGU_VERIFIKASI_PEMBAYARAN`. Cancellation from the verification-pending state is admin-only, because unverified money may already have moved.
- `KEDALUWARSA`: quote or payment window expired. Reachable from `PENAWARAN_TERKIRIM`, `DISETUJUI_PEMESAN`, and `MENUNGGU_PEMBAYARAN` — never from `MENUNGGU_VERIFIKASI_PEMBAYARAN`, where submitted evidence must be decided, not left to lapse.
- `DITOLAK_SETELAH_BAYAR`: admin refusal of an order whose money has **already arrived**; reason mandatory. Reachable only from `DIBAYAR_MENUNGGU_KONFIRMASI`, and only when a `refund_obligations` row for the order already exists — see the rule below.
- Nothing terminal is reachable after `DIBAYAR`: once money is confirmed, correction happens through a compensating financial action (payment reversal), never a status edge. **This rule is UNCHANGED by the 13 Sep 2026 pay-first flow, word for word.** `DIBAYAR`'s outgoing edges are still exactly `['DIPROSES']`. The pay-first flow does not add a terminal edge to `DIBAYAR`; it introduces a *different* state, `DIBAYAR_MENUNGGU_KONFIRMASI`, for the case the old vocabulary had no word for — money has arrived but nobody has accepted the order yet. `DITOLAK_SETELAH_BAYAR` leaves that state, never `DIBAYAR`. An order that reaches `DIBAYAR` still cannot be refused, cancelled, or expired, and is still corrected only by a compensating financial action.
- A paid order cannot be refused without recording the debt. `DITOLAK_SETELAH_BAYAR` may only be written when a `refund_obligations` row for that order already exists **in the same transaction** — a data precondition enforced by `App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange`, not a flag a caller asserts. `App\Domain\OrderWorkflow\Actions\RefusePaidOrder` is the single door that satisfies it, opening the obligation and recording the refusal together or not at all. The obligation carries the amount owed and a 3-working-day execution deadline (`docs/superpowers/plans/2026-09-13-sistem-refund.md`).
- A refused paid order returns its plot to inventory, exactly as `DITOLAK` does. Because `DITOLAK_SETELAH_BAYAR` answers `true` to `OrderStatus::isPaidOrLater()`, that release goes through `ReleasePlotReservation`'s paid-order override and is audited as `PLOT_RESERVATION_RELEASED_PAID_ORDER_OVERRIDE` (DOM-08), never as a routine release.
- No transition backward.
- Correction creates a new reasoned event/status or financial compensating action.

## 4. Urgent rules

- Feature flag and operating-hour check before submission.
- Priority assignment and separate notification route.
- Do not promise same-day fulfilment solely from software state.
- Operational escalation must exist outside the application.

## 5. Pre-Need rules

While legal gate closed:

```text
INTEREST_REGISTERED -> CONTACTED -> CLOSED
```

No invoice, payment session, or financial obligation may be created.
