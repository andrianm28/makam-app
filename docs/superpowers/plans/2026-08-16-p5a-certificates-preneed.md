# P5a — Certificates & Agreements + Pre-Need Contracting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Certificates and agreements as versioned, eligibility-driven, vault-backed domain records with an audited issuer surface; pre-need's paid flow fully built and tested to the honest G-LEGAL-01 fail-closed state, with the public interest/consultation surface.

**Architecture:** Three file-disjoint lanes. Lane 1: AgreementCertificate domain + admin (DocumentKind::Agreement/Certificate PDF vault kinds, eligibility rules separate from payment status, history-preserving replace, `certificate.issued.v1`/`replaced.v1`/`agreement.accepted.v1` — pre-catalogued names). Lane 2: PreNeedCase + the seven paid-flow actions (uniform `PreNeedGateClosedException` while G-LEGAL-01 is closed; the pre-need ORDER seam resolves quote/reservation/payment), PreNeedCasesResource admin. Lane 3: public interest + consultation form + customer certificate status view. Spec: `docs/superpowers/specs/2026-08-16-p5a-certificates-preneed-design.md`.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / Livewire 4 / PostgreSQL 18 + SQLite (tests).

## Global Constraints

- G-LEGAL-01 via `ModeResolver::preNeedMode()`: closed → every paid-flow action throws the uniform `PreNeedGateClosedException` (no state change; the attempt audited as denied); interest + consultation NEVER gated.
- Event names come from the catalog FIRST: `agreement.accepted.v1`, `certificate.issued.v1`, `certificate.replaced.v1` already exist — use exactly those; no pre_need_interest event is catalogued (none needed).
- `DocumentKind::Agreement`/`Certificate` are the PDF-only vault kinds (10 MB cap, `application/pdf`); issued certificates reference only `DocumentState::Accepted` documents.
- Certificate `reference` unique per issuer+type (AC7); replace preserves history (new version row, old rows untouched — AC5); eligibility rule objects NEVER read payment status directly (AC3); the customer status view exposes state only, never the vault reference or subject internals (AC6); external references recorded without claiming platform issuance (AC8).
- Issuer role gate: admin + restricted_admin for issuance/revocation/replacement; operator/finance view; `MasterDataAdminAuthorizerContract` + `auditRoleFor` everywhere.
- Pre-need quote/reservation/payment seams resolve through the pre-need ORDER (SubmitBookingDraft creates it at PRE_NEED with `pre_need_case_id`): `QuotePreNeed` → `IssueQuote` on that order; `ReservePreNeedPlot` → `ReservePlot` on that order; installments → `OpenPaymentSession` with the order's reference (documented: `OrderType::Booking` is the order-backed session type — the enum name is a known limitation, adjudicated at review).
- `SchedulePreNeedPayments` is net-new infrastructure (`pre_need_payment_schedules` rows: installment number, amount, due date, state, payment_session_id nullable) — explicit + idempotent (AC6); no raw payment instruments (AC8).
- `ActivatePreNeed` (AC8): creates a new At-Need FuneralCase via `OpenFuneralCase` + links it to the case WITHOUT losing contract history (the case keeps its agreement/quote/reservation links).
- `pre_need_interests` CHECK constraints are closed (3 statuses, 2 gate modes) — the new `PreNeedCase` is a NEW table; the existing interest rows stay untouched.
- Gates: `composer lint`, `composer analyse`, `php artisan test` per lane; CI (incl. PG18) gates every merge — no forward class references (phpstan 0 per PR; staged dispatch: domains L1+L2 first, surfaces after).
- Worktree execution: branch per lane from `docs/design-system-and-planning`; ledger at `.superpowers/sdd/2026-08-16-p5a-certificates-preneed/progress.md`.

---

## Task 1: AgreementCertificate domain (Lane 1)

**Files:**
- Create: migrations `create_agreements_table`, `create_certificates_table`, `create_external_certificate_references_table`
- Create: `app/Domain/AgreementCertificate/Models/Agreement.php`, `Certificate.php`, `ExternalCertificateReference.php`
- Create: `app/Domain/AgreementCertificate/AgreementStatus.php`, `CertificateStatus.php`, `AgreementCertificateAuditActions.php`
- Create: `app/Domain/AgreementCertificate/CertificateEligibilityPolicy.php`
- Create: `app/Domain/AgreementCertificate/Exceptions/CertificateEligibilityNotMetException.php`, `CertificateIssuerNotAuthorisedException.php`
- Create: `app/Domain/AgreementCertificate/Actions/CreateAgreement.php`, `AcceptAgreement.php`, `SupersedeAgreement.php`, `IssueCertificate.php`, `RevokeCertificate.php`, `ReplaceCertificate.php`
- Create: `app/Domain/AgreementCertificate/CertificateStatusView.php`
- Modify: `docs/contracts/event-catalog.md` (verify the three pre-catalogued rows; no new rows needed unless an action emits a new name — do NOT invent)
- Test: `tests/Feature/Domain/AgreementCertificate/AgreementTest.php`, `CertificateTest.php`, `CertificateEligibilityTest.php`

**Interfaces:**
- Consumes: `DocumentKind::Certificate`/`Agreement` (PDF-only vault kinds), `UploadDocument::upload` (quarantine → scan → `promote()`), `DocumentState::Accepted`, `Audit::wrap`/`Outbox::record`, `ActorRole`, `MasterDataAdminAuthorizerContract` (surface-side).
- Produces:
  - `Agreement` (table `agreements`): fillable `['reference','type','version_number','status','subject_type','subject_id','accepted_by_ref','accepted_quote_id','accepted_agreement_version_id','price_guarantee','cancellation_refund','transferability','term','included_services','responsible_entity']`; version_number int; status draft/accepted/active/superseded (`AgreementStatus` constants); AC4 display fields explicit approved strings; `accept(CarbonInterface $now, string $actorRef, ?string $quoteId): void` (draft→accepted binds actor + exact versions — AC2); `supersede(): void` (active→superseded); delete blocked while certificates reference it.
  - `Certificate` (table `certificates`): fillable `['reference','type','version_number','status','subject_type','subject_id','issued_by_ref','effective_at','document_id']`; status draft/issued/revoked/replaced (`CertificateStatus` constants); `document_id` references a vault Document (Accepted-only); delete blocked when issued.
  - `ExternalCertificateReference` (table `external_certificate_references`): fillable `['issuer_ref','reference','type','subject_type','subject_id']` — the AC8 manual-external record.
  - `CertificateEligibilityPolicy::eligibleFor(string $certificateType, Model $subject): bool` — rule objects per type; `CERTIFICATE_ELIGIBILITY_RULES` map (type → Closure/rule class); rules evaluate domain state (e.g. order DIBAYAR via `Order::status()` — NOT payment-status columns; a settled pre-need case). Never touches `payment_state`/`paid_via` directly.
  - `AgreementCertificateAuditActions`: `CERTIFICATE_ISSUED='CERTIFICATE_ISSUED'`, `CERTIFICATE_REVOKED='CERTIFICATE_REVOKED'`, `CERTIFICATE_REPLACED='CERTIFICATE_REPLACED'`, `AGREEMENT_CREATED='AGREEMENT_CREATED'`, `AGREEMENT_ACCEPTED='AGREEMENT_ACCEPTED'`, `AGREEMENT_SUPERSEDED='AGREEMENT_SUPERSEDED'` — none on SensitiveActions.
  - `IssueCertificate::__invoke(CertificateType $type, Model $subject, int|string $issuerReference, string $issuerRole, ?string $documentId, ?string $reason = null, AuditSource $auditSource = AuditSource::Panel): Certificate` — issuer role gate (admin/restricted_admin else `CertificateIssuerNotAuthorisedException`), eligibility check (`CertificateEligibilityPolicy` else `CertificateEligibilityNotMetException`), reference generation (per issuer+type unique — `'CERT-'.Str::upper(Str::random(8))` with the DB unique backstop + classifier), document Accepted check, audit `CERTIFICATE_ISSUED`, outbox `certificate.issued.v1` (idempotency key `certificate:{$id}`).
  - `RevokeCertificate::__invoke(...)` (issued→revoked; audit `CERTIFICATE_REVOKED`), `ReplaceCertificate::__invoke(...)` (issued→replaced: supersede the old row's status, issue a NEW version row preserving history — audit `CERTIFICATE_REPLACED` + outbox `certificate.replaced.v1`).
  - `CreateAgreement` (audit `AGREEMENT_CREATED`), `AcceptAgreement::__invoke(Agreement $agreement, string $actorRef, ?string $quoteId, ?string $agreementVersionId, AuditSource ...): Agreement` (AC2 binding; audit `AGREEMENT_ACCEPTED`; outbox `agreement.accepted.v1` with the exact versions in the payload), `SupersedeAgreement` (audit `AGREEMENT_SUPERSEDED`).
  - `CertificateStatusView::forSubject(Model $subject): array` — per certificate: type, status, version, effective_at, issued_by role — NO document_id, NO subject internals (AC6).

- [ ] **Step 1: Write the failing tests** — Agreement: create/accept (AC2 binding asserted: accepted_by_ref + accepted_quote_id + accepted_agreement_version_id), supersede preserves the old row; Certificate: issue happy path (role gate passes, eligibility passes, unique reference, audit + outbox `certificate.issued.v1`), issuer-refused (operator), eligibility-refused (rule not met → `CertificateEligibilityNotMetException`, nothing written), document-not-accepted refused (a Quarantined document id → refusal), revoke, replace (old row preserved + new version row + outbox `certificate.replaced.v1`), uniqueness backstop (same issuer+type reference collision → classifier), external reference (recorded, flagged external); StatusView: never contains document_id/subject internals (assert the array keys).
- [ ] **Step 2: Run to verify they fail** → FAIL.
- [ ] **Step 3: Implement** per the Produces block.
- [ ] **Step 4: Run tests** → PASS. Verify the catalog rows.
- [ ] **Step 5: Gates + commit** — `feat(certificates): versioned agreement/certificate domain with eligibility and vault documents (P5a lane 1)`.

---

## Task 2: Certificate/Agreement admin + customer status view (Lane 1)

**Files:**
- Create: `app/Filament/Admin/Resources/Certificates/CertificatesResource.php` + Pages + Schemas + Tables
- Create: `app/Filament/Admin/Resources/Agreements/AgreementsResource.php` + Pages + Schemas + Tables
- Create: `app/Livewire/Public/Certificates/CertificateStatusPage.php` + blade (the AC6 customer view)
- Modify: `routes/web.php` (`/sertifikat/{subjectType}/{subjectId}` → the status page + comment block)
- Test: `tests/Feature/Filament/CertificateAdminTest.php`, `tests/Feature/Livewire/Public/Certificates/CertificateStatusPageTest.php`

**Interfaces:**
- Consumes: Task 1's domain; `MasterDataAdminAuthorizerContract`; the P1 case-detail pattern.
- Produces: `CertificatesResource` (list: reference/type/status/version/subject; view with the status info; header actions per state: Terbitkan (issuer role gate both layers — `->authorize()` + in-closure role check), Cabut, Ganti — each routing to the domain actions with honest error notifications; a CreateAction with the subject select (order/pre-need case) + document upload (vault — the `UploadDocument` seam with `DocumentKind::Certificate`)); `AgreementsResource` (list + view: AC4 display fields + accept/supersede actions); the public `CertificateStatusPage` (by subjectType+subjectId: state-only table, NEVER the vault reference; 404-discipline on unknown).

- [ ] **Step 1: Write the failing tests** — access matrix (issuer roles; operator/finance view-only — the actions hidden/denied); issue via the resource with a real vault upload (the UploadDocument test fixture shape — check the existing DocumentVault feature tests for the file fixture pattern); the honest denial for operator issuance; the status page renders state only (assert no document_id/reference in the HTML) + 404 unknown.
- [ ] **Step 2: Run to verify they fail** → FAIL.
- [ ] **Step 3: Implement** per the Produces block (the P1 action-factory + redirect pattern).
- [ ] **Step 4: Run tests + gates + commit** — `feat(admin): certificate and agreement resources with issuer-gated issuance (P5a lane 1)`.

---

## Task 3: PreNeed paid-flow domain (Lane 2)

**Files:**
- Create: migration `create_pre_need_cases_table`, `create_pre_need_payment_schedules_table`
- Create: `app/Domain/PreNeed/Models/PreNeedCase.php`, `PreNeedPaymentScheduleItem.php`
- Create: `app/Domain/PreNeed/PreNeedCaseStatus.php`, `PreNeedAuditActions.php`
- Create: `app/Domain/PreNeed/Exceptions/PreNeedGateClosedException.php`, `IllegalPreNeedCaseTransitionException.php`
- Create: `app/Domain/PreNeed/Actions/ProposePreNeedPackage.php`, `ReservePreNeedPlot.php`, `QuotePreNeed.php`, `AcceptPreNeedAgreement.php`, `SchedulePreNeedPayments.php`, `SettlePreNeed.php`, `ActivatePreNeed.php`
- Modify: `docs/contracts/event-catalog.md` (verify `agreement.accepted.v1` producer now includes PreNeed; add `pre_need_case.activated.v1` if it's not catalogued — if absent, append it; do NOT invent names already present)
- Test: `tests/Feature/Domain/PreNeed/PreNeedGateClosedTest.php`, `tests/Feature/Domain/PreNeed/PreNeedPaidFlowTest.php`

**Interfaces:**
- Consumes: `ModeResolver::preNeedMode()`; the pre-need ORDER (SubmitBookingDraft's PRE_NEED order — `order.pre_need_case_id` holds the interest id; the case's order via a new `pre_need_orders` lookup or the draft chain — verify: the case links its interest → booking_draft → the order's `booking_draft_id`); `IssueQuote` (on the pre-need order), `ReservePlot` (on the pre-need order), `OpenPaymentSession` (the order's reference — `OrderType::Booking` documented), `OpenFuneralCase` (AC8), Task 1's `Agreement`/`AcceptAgreement`.
- Produces:
  - `PreNeedCase` (table `pre_need_cases`): fillable `['pre_need_interest_id','status','cemetery_id','cemetery_package_id','agreement_id','quote_id','plot_reservation_id','activated_funeral_case_id']`; status interest/proposal/reserved/quoted/agreed/scheduled/settled/activated (`PreNeedCaseStatus` constants + `allowedNext()`); the case keeps its full history (no deletes).
  - `PreNeedPaymentScheduleItem` (table `pre_need_payment_schedules`): fillable `['pre_need_case_id','installment_number','amount_minor','currency','due_date','state','payment_session_id']`; state `pending`/`paid`/`overdue`; unique (case_id, installment_number).
  - `PreNeedAuditActions`: `PRENEED_PROPOSED`, `PRENEED_RESERVED`, `PRENEED_QUOTED`, `PRENEED_AGREEMENT_ACCEPTED`, `PRENEED_SCHEDULED`, `PRENEED_SETTLED`, `PRENEED_ACTIVATED`, `PRENEED_GATE_DENIED`.
  - Each paid action begins with the gate check — the shared shape:

```php
private function assertGateOpen(): void
{
    if (app(ModeResolver::class)->preNeedMode() !== PreNeedMode::PaymentEnabled) {
        throw PreNeedGateClosedException::becauseLegalGateClosed();
    }
}
```

  - `ProposePreNeedPackage::__invoke(PreNeedCase $case, Cemetery $cemetery, ?CemeteryPackage $package, int|string $actorReference, string $actorRole = 'admin', AuditSource $auditSource = AuditSource::Panel): PreNeedCase` — gate → interest/proposal → proposal state (+ cemetery/package refs); audit `PRENEED_PROPOSED`; the denied attempt audited as `PRENEED_GATE_DENIED` (the gate check FIRST, denial audit inside a try/catch or a wrapper — the pattern: the gate check throws; the CALLER-side audit of the denial happens in the admin surface or a small `PreNeedGate` helper that audits then throws).
  - `ReservePreNeedPlot::__invoke(PreNeedCase $case, GravePlot $plot, int|string $actorReference, string $actorRole, ...): PreNeedCase` — gate → `ReservePlot` on the case's pre-need order → links plot_reservation_id.
  - `QuotePreNeed::__invoke(PreNeedCase $case, CarbonInterface $expiresAt, int|string $actorReference, string $actorRole, ...): PreNeedCase` — gate → `IssueQuote` on the pre-need order (lines from the draft's selected services via `ComposeQuoteLinesFromBookingDraft` — the P0 seam) → links quote_id.
  - `AcceptPreNeedAgreement::__invoke(PreNeedCase $case, Agreement $agreement, string $actorRef, ...): PreNeedCase` — gate → `AcceptAgreement` (AC2 binding) → links agreement_id → agreed state.
  - `SchedulePreNeedPayments::__invoke(PreNeedCase $case, array $installments /* list<array{amount_minor:int, due_date:string}> */, int|string $actorReference, string $actorRole, ...): PreNeedCase` — gate → create the schedule rows (idempotent: existing schedule → incumbent) → scheduled state; each installment's payment-link opening is a SEPARATE later step (the admin surface opens per-installment sessions via `OpenPaymentSession`).
  - `SettlePreNeed::__invoke(PreNeedCase $case, string $paidSourceRef, int|string $actorReference, string $actorRole, ...): PreNeedCase` — gate → settled state (webhook-validated paid state — the manual-fallback admin path only after payment verification; mirrors the P1 MarkOrderPaid discipline: settled only when the payment evidence is verified).
  - `ActivatePreNeed::__invoke(PreNeedCase $case, BookingDraft $activationDraft, int|string $actorReference, string $actorRole, ...): PreNeedCase` — gate → `OpenFuneralCase($activationDraft)` → activated state + activated_funeral_case_id (AC8: the original case's agreement/quote/reservation links untouched); outbox `pre_need_case.activated.v1` (append to the catalog if absent).

- [ ] **Step 1: Write the failing tests** — `PreNeedGateClosedTest`: with G-LEGAL-01 closed (the ModeResolverTest in-memory source pattern — bind the gate registry in-test), EVERY paid action throws the uniform `PreNeedGateClosedException`, no state change, `PRENEED_GATE_DENIED` audited; interest + consultation unaffected. `PreNeedPaidFlowTest`: with the gate test-opened, the full happy path — proposal → reservation → quote → agreement accept (AC2 binding asserted) → schedule (idempotent on re-run) → settle → activate (a NEW FuneralCase exists, the case keeps its history — assert agreement_id/quote_id/reservation_id intact, AC8).
- [ ] **Step 2: Run to verify they fail** → FAIL.
- [ ] **Step 3: Implement** per the Produces block. The pre-need order resolution: `PreNeedCase::order(): ?Order` — the order whose `booking_draft_id` matches the case's interest's `booking_draft_id` (the submit-time chain); verify the chain in the code and document it.
- [ ] **Step 4: Run tests + catalog check + gates + commit** — `feat(pre-need): fail-closed paid flow with schedule, settlement, activation (P5a lane 2)`.

---

## Task 4: PreNeed admin resource (Lane 2)

**Files:**
- Create: `app/Filament/Admin/Resources/PreNeedCases/PreNeedCaseResource.php` + Pages + Schemas + Tables
- Create: `app/Filament/Admin/Resources/PreNeedCases/Actions/PreNeedCaseActions.php` (the paid-flow action factories)
- Test: `tests/Feature/Filament/PreNeedCaseResourceTest.php`

**Interfaces:**
- Consumes: Task 3's domain + Task 1's agreements/certificates (the eligibility-driven certificate issuance at settlement).
- Produces: `PreNeedCaseResource` (list: interest ref, status badge, cemetery, agreement/quote; view: the case detail sections — proposal (cemetery/package), reservation (plot ref), quote (total/status), agreement (AC4 fields), schedule (installments + per-installment payment-link actions via `OpenPaymentSession` on the pre-need order — the P1 money-action shape + ReauthenticationGuard), eligibility (the certificate eligibility rule state), header actions per-edge for the paid-flow actions (each role-gated + the gate denial surfaces the honest 'Belum dapat diaktifkan' notification while G-LEGAL-01 is closed on dev — the P1 action-factory + redirect pattern).

- [ ] **Step 1: Write the failing tests** — access matrix; the proposal action → proposal state; the gate-closed denial surfaces as the honest notification with no state change; the schedule action creates installments; the per-installment payment-link action opens a PaymentSession (the merchant-bound test fixture — the OpenPaymentSessionTest pattern); settlement via the verified-payment path.
- [ ] **Step 2: Run to verify it fails** → FAIL.
- [ ] **Step 3: Implement** per the Produces block.
- [ ] **Step 4: Run tests + gates + commit** — `feat(admin): pre-need case resource with paid-flow actions (P5a lane 2)`.

---

## Task 5: Public pre-need surface + certificate status page (Lane 3)

**Files:**
- Create: `app/Livewire/Public/PreNeed/PreNeedInterestPage.php` + blade
- Create: `app/Domain/PreNeed/Actions/RequestPreNeedConsultation.php`
- Create: `app/Domain/PreNeed/Models/PreNeedConsultationRequest.php` + migration `create_pre_need_consultation_requests_table`
- Modify: `routes/web.php` (the pre-need route + the certificate status route if not added in Task 2 — coordinate: the certificate status page lives in Task 2; this lane adds the pre-need route)
- Test: `tests/Feature/Livewire/Public/PreNeed/PreNeedInterestPageTest.php`, `tests/Feature/Domain/PreNeed/RequestPreNeedConsultationTest.php`

**Interfaces:**
- Consumes: `RegisterPreNeedInterest` (interest via a draft-shaped payload — verify: it takes a BookingDraft; the public pre-need form's submission path needs a draft or a new interest-creation seam — DECISION: the public page creates a minimal BookingDraft (service_type PRE_NEED_PLOT_PURCHASE — verify the BookingServiceType value) then `RegisterPreNeedInterest`, mirroring the wizard's start path; document the draft usage), Task 1's `CertificateStatusView`.
- Produces: `PreNeedInterestPage` (Livewire, `/preneed`): the interest form (name/contact/city/service area + the honest InterestOnly copy while the gate is closed — non-dismissible info banner via the mode's fallback), submit → interest registered (reference + 'tim akan menghubungi Anda' confirmation) + the consultation request (contact + message → `PreNeedConsultationRequest` row — audited `PRENEED_CONSULTATION_REQUESTED`); the customer certificate status section (by subject reference — the Task 2 status view); routes + comment blocks citing the spec.

- [ ] **Step 1: Write the failing tests** — the interest form registers + confirms (gate closed OK); the consultation request persists + audits; the info banner shows while InterestOnly; the certificate status section renders state-only.
- [ ] **Step 2: Run to verify it fails** → FAIL.
- [ ] **Step 3: Implement** per the Produces block (the RenewalStart page pattern + the wizard's draft-start seam — verify `StartBookingDraft`'s signature for a minimal pre-need draft).
- [ ] **Step 4: Run tests + gates + commit** — `feat(public): pre-need interest and consultation surface (P5a lane 3)`.

---

## Task 6: Docs + deploy + browser UAT + whole-branch review (post-merge)

**Files:**
- Modify: `docs/product/screen-inventory.md` (certificates/agreements/pre-need case resources + the public pre-need page + certificate status page), `docs/domain/traceability-matrix.md` (P5a rows Covered with the real test files), `docs/operations/feature-flag-registry.md` (verify G-LEGAL-01 wording matches the shipped fail-closed behavior)
- Test: Playwright additions (dev).

- [ ] **Step 1: Update screen inventory + traceability** (in-place; commit `docs: screen inventory and traceability for P5a certificates + pre-need`).
- [ ] **Step 2: Deploy to dev** (digest → compose update → migrate → health check).
- [ ] **Step 3: Browser UAT** (admin certificates issue with a vault PDF; pre-need case proposal action → the honest gate-closed denial on dev; public /preneed interest + consultation form; customer certificate status view).
- [ ] **Step 4: Whole-branch review** (full phase diff, ledger minors triage, bounded fix wave + scoped re-review) then final merge + deploy.

---

## Self-review notes

- **Spec coverage:** §4.1 → Tasks 1–2; §4.2 → Tasks 3–4; public surfaces → Task 5; §5/§6 → per-task; §7 → per-task tests + Task 6; §8 → Task 6.
- **Type consistency:** `PreNeedGateClosedException` uniform across all seven paid actions (Tasks 3/4); the pre-need order resolution shared; `IssueCertificate`/`AcceptAgreement` signatures identical in Tasks 1/2/3/4; the catalogued event names exact.
- **Known drift risks to resolve at implementation time:** the public interest form's BookingDraft usage (StartBookingDraft signature + BookingServiceType::PRE_NEED value — verify); `OrderType::Booking` reuse for pre-need installments (adjudicated at review — documented); the pre-need order resolution chain (interest → booking_draft → order.booking_draft_id); `PreNeedCase::order()` may return null when the draft chain is broken (honest refusal); the vault upload fixture shape in Filament tests (the DocumentVault test precedent); the eligibility rule for the pre-need certificate type (settled case).
