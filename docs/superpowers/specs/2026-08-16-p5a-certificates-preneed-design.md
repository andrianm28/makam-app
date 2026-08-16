# P5a — Certificates & Agreements + Pre-Need Contracting Design

**Date:** 16 Aug 2026
**Status:** Draft (approved by user 16 Aug 2026, pending written review)
**Scope:** The first of three P5 sub-phases (roadmap P5 = certificates, care subscriptions, vendor fulfillment, pre-need, operator dashboard; decomposition approved: P5a = certificates+agreements + pre-need completion; P5b = care+fulfillment; P5c = operator dashboard — each its own spec/plan/cycle). P5a implements the kiro specs `certificates-and-agreements` (AC1–AC8) and `pre-need-contracting` (AC1–AC8), with pre-need built to the honest G-LEGAL-01 fail-closed state.
**Depends on:** P0–P4 (order/quote/payment machinery, plot reservation, the pre-need interest seam).

## 1. Goal

Certificates and agreements are first-class domain records: stable references, versioned, eligibility-driven, history-preserving, vault-backed documents, audited issuance — with a customer status view that never exposes restricted sources. Pre-need's paid flow (proposal → reservation → quote → agreement → payment schedule → settlement → activation) is fully built and tested to the fail-closed state while G-LEGAL-01 is closed; the public surface ships the interest + consultation flow.

## 2. In scope

1. **AgreementCertificate domain**: `Certificate` + `Agreement` records, `CertificateEligibilityPolicy` (rule objects, separate from payment status — AC3), `ExternalCertificateReference` (AC8), issuance/revocation/replacement actions (AC4/AC5/AC7), acceptance binding (AC2), vault document references (quarantine→scan→accept via `UploadDocument`).
2. **PreNeed domain completion**: `PreNeedCase` aggregate + the paid-flow actions (proposal, optional P3 reservation, quote, agreement accept, payment schedule, settlement, activation — AC8 linking a new At-Need FuneralCase with history intact), each G-LEGAL-01-gated fail-closed; the existing interest flow + `RequestPreNeedConsultation`.
3. **Surfaces**: admin `CertificatesResource` + `AgreementsResource` + `PreNeedCasesResource` (case detail incl. proposal/reservation/quote/agreement/schedule/eligibility-driven certificate issuance); public interest + consultation form; customer certificate status view (state only — AC6).
4. **Auth**: issuer role gate (admin + restricted_admin for issuance/revocation/replacement; operator/finance view), `MasterDataAdminAuthorizerContract` + auditRoleFor everywhere; every write audited.

## 3. Out of scope

- P5b (care subscriptions + vendor fulfillment), P5c (operator dashboard) — their own sub-phase specs.
- Public pre-need paid-flow UI (the domain is ready; the UI lands when the gate opens or a later public phase — recorded).
- Auto-generated certificate PDFs (vault-uploaded documents only).
- Cancellation/pause/grace policies (pre-need AC6's delinquency behavior is explicit + idempotent this phase; grace/dunning policy is a P6/FIN-DEC item).
- Land-rights listing (AGENTS.md).

## 4. Architecture

### 4.1 Agreements & certificates

- `agreements`: uuid id, `reference` (unique per issuer+type), `type`, `version_number` (int), `status` (draft/accepted/active/superseded), `subject_type`+`subject_id` (morph: order | pre_need_case), `accepted_by_ref`, `accepted_quote_id`, `accepted_agreement_version_id`, AC4 display fields (price_guarantee, cancellation_refund, transferability, term, included_services, responsible_entity — explicit approved values), timestamps. Versioned: supersession inserts a new row; old rows preserved (AC5).
- `certificates`: uuid id, `reference` (unique per issuer+type — AC7), `type`, `version_number`, `status` (draft/issued/revoked/replaced), `subject_type`+`subject_id`, `issued_by_ref`, `effective_at`, nullable `document_id` (vault `Document` — uploaded via `UploadDocument`; usable only when `DocumentState::Accepted`), timestamps.
- `external_certificate_references`: the manual external reference (issuer, reference, type) — recorded WITHOUT claiming platform issuance (AC8); flagged as external in every display.
- `CertificateEligibilityPolicy`: per certificate type, a rule object (e.g. settled order / settled pre-need agreement) evaluated against domain state — NEVER reading payment status directly (AC3); `eligibleFor(owner): bool`; each rule tested.
- Actions: `IssueCertificate` (eligibility check + issuer role gate + audit `CERTIFICATE_ISSUED`), `RevokeCertificate` (audit `CERTIFICATE_REVOKED`), `ReplaceCertificate` (new version + old history preserved — audit `CERTIFICATE_REPLACED`), `CreateAgreement`, `AcceptAgreement` (binds actor + exact quote/agreement versions — AC2), `SupersedeAgreement`.
- Customer status view: a `CertificateStatusView` projection (status + issued/effective dates + delivery state ONLY — the vault reference and subject internals never leave the server; AC6).

### 4.2 Pre-need

- `pre_need_cases`: uuid id, `pre_need_interest_id`, `status` (interest/proposal/reserved/quoted/agreed/scheduled/settled/activated), `cemetery_id`, `cemetery_package_id`, `agreement_id`, `quote_id`, `plot_reservation_id` (P3 seam), timestamps. The P0 seam (`orders.pre_need_case_id`) already links; AC8 activation links a NEW At-Need FuneralCase with the original case history intact.
- Paid-flow actions (each begins with the G-LEGAL-01 check via `ModeResolver::preNeedMode()`; closed → `PreNeedGateClosedException`, no state change, the attempt audited as denied):
  - `ProposePreNeedPackage` (case → proposal state; package/plot proposal),
  - `ReservePreNeedPlot` (optional — `ReservePlot` seam),
  - `QuotePreNeed` (`IssueQuote` seam),
  - `AcceptPreNeedAgreement` (binds the exact agreement + quote versions — AC5),
  - `SchedulePreNeedPayments` (installment schedule — explicit, idempotent — AC6; each installment opens a payment-link session via the existing machinery),
  - `SettlePreNeed` (settlement — webhook-validated paid state),
  - `ActivatePreNeed` (creates the At-Need FuneralCase — AC8; gate refuses while closed).
- Public surface: the existing interest flow + `RequestPreNeedConsultation` (+ a consultation-request form); the paid-flow UI is deferred (recorded).
- Admin: `PreNeedCasesResource` (case detail + the paid-flow actions as role-gated actions — they refuse honestly while the gate is closed on dev), `AgreementsResource`, `CertificatesResource`.

## 5. Data flow

Issuer → eligibility rule → `IssueCertificate` (role gate → audit → outbox `certificate.issued.v1` [new catalogued event] ) → customer status view (state only). Pre-need: interest → consultation (gate closed OK) → [gate open] proposal → optional reservation → quote → agreement accept (AC2 binding) → payment schedule (AC6 idempotent installments) → settlement → eligibility-driven certificate → activation (AC8 new FuneralCase, history intact).

## 6. Error handling

- `PreNeedGateClosedException` (uniform for every paid act; the public render never reveals the gate's internals beyond the honest "belum dapat diaktifkan" copy).
- Eligibility failures → honest refusal, no issuance; replace/revoke of terminal states refused.
- Vault uploads follow the quarantine/scan/accept flow (rejected documents can never be referenced by an issued certificate).
- All domain exceptions → notifications/inline errors, never 500s.

## 7. Testing

- Certificates: uniqueness per issuer+type; eligibility rules (eligible/not, independent of payment status); issuance/revoke/replace with history + audit; role gate (issuer-refused); customer status view (no vault reference, state only); external reference (no platform-issuance claim).
- Pre-need: interest + consultation (gate closed OK); EVERY paid-flow action refused while G-LEGAL-01 closed (uniform exception, no state change, denied audit); the full happy path with the gate test-opened (proposal → reservation → quote → accept → schedule → settle → activate with a new FuneralCase + intact history — AC8); AC4 display fields; schedule idempotency (AC6); settlement-driven certificate eligibility.
- Browser (dev): admin certificate/pre-need resources; the public interest + consultation form; the paid-flow actions' honest refusal in the admin UI while G-LEGAL-01 stays closed.

## 8. Delivery

One plan, lanes: L1 agreements+certificates domain + admin; L2 pre-need paid-flow domain (fail-closed) + admin; L3 public interest/consultation + customer certificate status. L2 consumes L1's agreement/certificate seams (plan-signature-pinned; staged dispatch: L1+L2 domains first, L3 after their merges), then deploy + browser UAT + whole-branch review per the established rhythm.
