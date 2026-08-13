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
-> DIBAYAR
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

Terminal branches:

```text
DITOLAK
DIBATALKAN
KEDALUWARSA
```

## 2. Transition matrix

| From | To | Actor/trigger | Guard | Required audit data |
|---|---|---|---|---|
| Draft submission | MASUK | Customer | Required data complete | actor, draft, timestamp |
| MASUK | DIVERIFIKASI | Admin | Identity/data review completed | verifier, note |
| DIVERIFIKASI | MENUNGGU_KETERSEDIAAN | Admin | PIC assigned | PIC, cemetery |
| MENUNGGU_KETERSEDIAAN | PENAWARAN_TERKIRIM | Admin | Availability confirmed manually | source, operator/admin note, quote version |
| PENAWARAN_TERKIRIM | DISETUJUI_PEMESAN | Customer | Quote not expired | customer, quote version |
| DISETUJUI_PEMESAN | MENUNGGU_PEMBAYARAN | Admin/system | Payment gate active and admin opens payment | actor, gate evidence |
| MENUNGGU_PEMBAYARAN | MENUNGGU_VERIFIKASI_PEMBAYARAN | Customer | Manual coordination chosen; evidence submitted | actor, verification reference |
| MENUNGGU_PEMBAYARAN | DIBAYAR | Valid webhook | Signature, amount, merchant, idempotency valid | provider transaction, journal ref |
| MENUNGGU_VERIFIKASI_PEMBAYARAN | DIBAYAR | Finance/admin | Approved manual verification; amount equals quote total | actor, verification reference |
| DIBAYAR | DIPROSES | Admin/vendor/operator | Fulfilment started | actor, work reference |
| DIPROSES | SELESAI | Admin | Completion evidence/confirmation | actor, evidence, note |

## 3. Branch rules

- `DITOLAK`: admin/operator availability input or verification decision; reason mandatory. Reachable only from `MASUK`, `DIVERIFIKASI`, and `MENUNGGU_KETERSEDIAAN` — never after a quote has been sent.
- `DIBATALKAN`: customer cancellation while policy permits; financial consequences are TBD. Reachable from `MASUK`, `DIVERIFIKASI`, `MENUNGGU_KETERSEDIAAN`, `PENAWARAN_TERKIRIM`, `DISETUJUI_PEMESAN`, `MENUNGGU_PEMBAYARAN`, and `MENUNGGU_VERIFIKASI_PEMBAYARAN`. Cancellation from the verification-pending state is admin-only, because unverified money may already have moved.
- `KEDALUWARSA`: quote or payment window expired. Reachable from `PENAWARAN_TERKIRIM`, `DISETUJUI_PEMESAN`, and `MENUNGGU_PEMBAYARAN` — never from `MENUNGGU_VERIFIKASI_PEMBAYARAN`, where submitted evidence must be decided, not left to lapse.
- Nothing terminal is reachable after `DIBAYAR`: once money is confirmed, correction happens through a compensating financial action (payment reversal), never a status edge.
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
