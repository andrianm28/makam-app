# P1 — Admin Order Management Design

**Date:** 15 Aug 2026
**Status:** Draft (approved by user 15 Aug 2026, pending written review)
**Scope:** Complete admin order management for the three order kinds — booking (OrderWorkflow), marketplace, renewal — per roadmap phase P1 (docs/superpowers/specs/2026-08-14-full-fledge-platform-roadmap.md, `## P1 — All orders managed by admin`).
**Depends on:** P0 (order→quote→payment-session chain, merged `9191191`, deployed to dev).

## 1. Goal

Make every order admin-managed. The operator side of the two-phase payment journey becomes fully clickable: a public booking order created by the P0 chain can be verified, quoted, accepted, granted payment opening (finance), paid (finance), processed, and completed — each transition a domain Action, role-gated, audited, and reflected in an append-only status timeline. The P0 guard's preconditions (valid confirmation, accepted quote, authorized opening) become reachable through the admin surface, so a buyer who re-clicks "Bayar Sekarang" after the operator completes the journey is redirected to the SumoPod hosted checkout.

## 2. In scope

- `BookingOrderResource` — list/status-filter, view (parties, quote, payments, documents, timeline, grants), transitions via domain Actions, manual-verification handoff (finance role), audit everywhere.
- `MarketplaceOrderResource` — list, view (items, vendor allocation, payable state), `MarkMarketplaceOrderPaid` (finance), vendor processing status read-only.
- `RenewalOrderResource` — list/detail/evidence (3 states), `MarkExternalRenewal` evidence recording (finance), expiry.
- Shared: `MasterDataAdminAuthorizer` (4 back-office roles: admin, restricted_admin, finance, operator), `Audit::record()` on every transition, finance/order-scope authorizer for money-adjacent actions under `RequireRecentAuthentication`.

## 3. Out of scope

- Scheduled KEDALUWARSA expiry job (deferred; manual expiry action included).
- Buyer-side public approval UI (buyer intent is inherent in re-clicking "Bayar Sekarang").
- Vendor-facing admin actions (vendor panel owns `UpdateVendorOrderStatus`; admin sees it read-only).
- Cemetery-scope filtering on resources (P2 data management).
- No Create pages — orders originate from the public flow. Edit limited to non-financial fields (documents, internal note).

## 4. Architecture

Resources live in `app/Filament/Admin/Resources/<Kind>/` (namespace `App\Filament\Admin\Resources\<Kind>`), discovered by `AdminPanelProvider` (`discoverResources(in: app_path('Filament/Admin/Resources'))`). Each mirrors the canonical FaqArticles pattern: Resource + `Tables/<Kind>Table` + `Schemas/<Kind>Infolist` + `Pages/List<Kind>s`, `Pages/View<Kind>` (+ `Pages/Edit<Kind>` where applicable) + status badges. No Create.

All state change flows through domain Actions. Filament resources and Livewire components never mutate order state directly. Every transition:
1. Resolves `ActorContext` from the authenticated admin (`ActorContextResolver`).
2. Authorizes the role for the transition (operator vs finance split below).
3. Invokes the domain Action, which writes state + outbox event + `Audit::record()` in the same transaction (the P0 pattern).
4. Surfaces domain exceptions as honest Filament notifications; no state change on failure.

### 4.1 Role split

| Roles | Transition domain |
|-------|-------------------|
| `admin`, `operator` | Non-money transitions: VerifyOrder, RequestAvailability, IssueQuote, RecordBuyerApproval, ProcessOrder, CompleteOrder, RejectOrder, CancelOrder, ExpireOrder |
| `admin`, `finance` | Money-adjacent transitions, each under `RequireRecentAuthentication`: AuthorizePaymentOpening (grant + transition), ManualPaymentVerification, MarkOrderPaid, MarkMarketplaceOrderPaid, MarkExternalRenewal |
| `admin` | Everything |
| `restricted_admin` | View + VerifyOrder, RequestAvailability, RecordBuyerApproval, ProcessOrder, CompleteOrder, RejectOrder, CancelOrder, ExpireOrder — but NOT IssueQuote (creates a binding quote); never money-adjacent |

`MasterDataAdminAuthorizer` implements this gate. Money-adjacent actions additionally require a fresh re-authentication (`RequireRecentAuthentication` middleware, route group already wired for `/admin`); if the window lapsed the action fails closed and redirects to the MFA challenge.

### 4.2 Operator Actions (BookingOrderResource)

One Action per allowed edge of the `OrderTransition` matrix, each invoking `RecordOrderStatusChange` (append-only `OrderStatusEvent` + catalogued outbox event + `Order::applyStatus`):

| From | To | Action | Role |
|------|----|--------|------|
| MASUK | DIVERIFIKASI | VerifyOrder | operator |
| DIVERIFIKASI | MENUNGGU_KETERSEDIAAN | RequestAvailability | operator |
| MENUNGGU_KETERSEDIAAN | PENAWARAN_TERKIRIM | IssueQuote (wires existing `App\Domain\Quotation\Actions\IssueQuote`) | operator |
| PENAWARAN_TERKIRIM | DISETUJUI_PEMESAN | RecordBuyerApproval | operator |
| DISETUJUI_PEMESAN | MENUNGGU_PEMBAYARAN | AuthorizePaymentOpening (invokes existing `GrantScopeAssignment` for ORDER scope + transition) | finance + re-auth |
| MENUNGGU_PEMBAYARAN | MENUNGGU_VERIFIKASI_PEMBAYARAN | ManualPaymentVerification | finance + re-auth |
| MENUNGGU_VERIFIKASI_PEMBAYARAN | DIBAYAR | MarkOrderPaid (wires existing `ApplyPaidEffects`) | finance + re-auth |
| DIBAYAR | DIPROSES | ProcessOrder | operator |
| DIPROSES | SELESAI | CompleteOrder | operator |
| (per matrix) | DITOLAK | RejectOrder | operator |
| (per matrix) | DIBATALKAN | CancelOrder | operator |
| (per matrix: PENAWARAN_TERKIRIM, DISETUJUI_PEMESAN, MENUNGGU_PEMBAYARAN) | KEDALUWARSA | ExpireOrder | operator |

The resource renders one header action per allowed edge from the record's current state (`OrderTransition::allowedFrom()`), with a confirmation modal noting the transition is audited. Domain exceptions (IllegalOrderTransition, PaidAmountDoesNotMatchQuote, etc.) surface as notifications; the failed money-adjacent attempt is audited with the actor identity.

### 4.3 BookingOrderResource surface

- **Table:** search (order ref, customer name), status filter over the 13 states (multi-select), columns ref / customer / service-package summary / total / status badge (color-coded) / created-at. Row → View.
- **View infolist sections:** Parties (`OrderParty` grouped by `OrderPartyRole` — pemesan, deceased), Quote (`Quote` lines incl. service-version anchors where present), Payments (PaymentSession status/amount), Documents (`OrderDocument` list + attach via existing `AttachOrderDocument`), Status events (append-only timeline), Active grants (ORDER-scope `ScopeAssignment` for this order).
- **Edit page:** attach documents / internal note only.
- **Delete:** disabled (append-only, audited lifecycle).

### 4.4 MarketplaceOrderResource

- **Table:** ref / vendor / items count / payable / payment status / created-at; search + status filter.
- **View:** items (`MarketplaceOrderItem`), vendor allocation (single vendor per checkout — MVP), payable state, payment status, vendor processing status (read-only, from `UpdateVendorOrderStatus`/`VendorProcessingStatus`).
- **Header action:** `MarkMarketplaceOrderPaid` (finance + re-auth), amount-checked against payable; reuses existing `MarkMarketplaceOrderPaid` action.
- No Create/Delete; Edit limited to internal note.

### 4.5 RenewalOrderResource

- **Table:** grave reference / period (cycle) / amount / status (MENUNGGU_PEMBAYARAN, DIBAYAR, KEDALUWARSA) / created-at; status filter.
- **View:** `RenewalQuote`, evidence (`RenewalExternalMarking`), timeline.
- **Header actions:** `MarkExternalRenewal` (finance + re-auth, records offline-payment evidence via existing action), expiry (operator).
- No Create/Delete; Edit limited to internal note.

## 5. Data flow

Header action → `ActorContext` from admin → authorizer gate (role + re-auth for money-adjacent) → domain Action → [state + outbox event + audit in one transaction] → notification + refresh. The public two-phase journey completes when the order reaches DISETUJUI_PEMESAN (or beyond) and the finance grant exists: the buyer re-clicking "Bayar Sekarang" passes the P0 guard and redirects to SumoPod.

## 6. Error handling

- Illegal transitions: `IllegalOrderTransitionException` (matrix guard) → notification, no state change.
- Role denial: authorizer exception → notification; money-adjacent denial audited.
- Re-auth lapse: fail closed, redirect to MFA challenge (`filament.admin.pages.mfa-challenge`).
- Amount mismatch on manual payment verification: `PaidAmountDoesNotMatchQuoteException` → notification.
- Channel/notification failures never change business state (queue contract).

## 7. Testing

- Unit: each operator Action — happy path, illegal-edge denial, role denial, audit row written, outbox event inserted (SQLite + PG18 regression gates, per P0 gate setup).
- Feature: authorizer gate matrix (role × transition × money-adjacent).
- Browser: admin login → MFA challenge → per-resource list/view/transition smoke; finance vs operator role denial; the full two-phase flow completes end-to-end on dev (operator accepts → finance authorizes → buyer re-click → SumoPod redirect).
- Policies/query scoping: order/vendor/business-entity scope on list queries.

## 8. Delivery

Single plan with three lanes (shared infra; booking resource + Actions; marketplace + renewal), one PR against `docs/design-system-and-planning`, two-tier review, merge, deploy to dev, UAT (Playwright + regression gates).
