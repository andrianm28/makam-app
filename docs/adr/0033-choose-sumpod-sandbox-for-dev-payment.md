# ADR-0033: Choose SumoPod Sandbox as the Dev/Staging Payment Provider

- **Status:** Accepted (Wave 0 ruling 0b, 09 Aug 2026, human-approved)

## Context

`docs/planning/sprint-plan.md` OQ-5 ("provider not chosen") and gate `G-PAY-01` ("online payment; activation evidence = shared money path and merchant active") blocked `platform-payment-adapter` and, transitively, booking Step 8, renewal Step 5, and marketplace checkout. No provider had been evaluated; `docs/contracts/payment-webhook.md` defined the application-side contract but no sandbox had been exercised.

On 09 Aug 2026 the user supplied SumoPod's sandbox documentation and a sandbox project key, and approved this ADR via the Wave 0 acceleration plan.

## Decision

**SumoPod sandbox (`https://api-pay-sandbox.sumopod.com`) is the chosen dev/staging payment provider** for Makam.co.id.

Binding details (from the sandbox documentation, exercised contract surface):

- **Create payment:** `POST /api/v1/payments` with `X-Api-Key` header.
  - Request: `order_id`, `amount`, `currency: "IDR"`, optional `expires_in_hours` (default 24, max 24), optional `success_return_url`/`cancel_return_url`, optional `payment_method_type_code` (e.g. `QRIS`).
  - Response: `payment_id` (uuid), `order_id`, `amount`, `fee`, `net_amount`, `payment_link_url` (hosted checkout), `payment_code`, `payment_code_type`, `payment_channel_used`, `status: "pending"`, `expires_at`.
  - Supported methods include QRIS (settles in 2 days; fee 0.7% + Rp 300).
- **Webhooks** (`payment.completed` / `payment.failed` / `payment.expired` / `payment.test`) delivered to the configured webhook URL:
  - Payload envelope: `event_type`, `data.payment_id`, `data.order_id`, `data.amount`, `data.fee`, `data.net_amount`, `data.status`, `data.payment_method`, `data.completed_at`.
  - Acknowledgment must be a 2xx within 10 seconds; failures are marked failed and can be resent.
  - Two verification mechanisms, either acceptable: Svix signatures (`svix-id`, `svix-timestamp`, `svix-signature`; HMAC-SHA256 over `{id}.{timestamp}.{rawBody}` with `whsec_…` secret) or a shared-token header `X-Webhook-Token` (`whtok_…`).

**Credential:** the variable name is `SUMODOP_SANDBOX_API_KEY`, injected only into the host `/opt/makam/compose/.env.dev` (protected injection). The value is a secret: it is never committed, logged, or placed in any repo file, per `AGENTS.md` §Authentication and uploads / §Observability. The webhook signing secret/token, once provisioned, follows the same rule.

## Consequences

What this unblocks:

- `platform-payment-adapter` (L3) can implement hosted checkout, the six-condition guard, and the durable/idempotent/signed webhook pipeline against a real sandbox, exercising `payment-webhook.md`'s contract before any production money path exists.
- `platform-financial-ledger` (L4) and booking Step 8 / renewal Step 5 / marketplace checkout are no longer blocked on provider choice at the dev/staging tier.

What this does **not** change:

- **`G-PAY-01` stays closed for production.** Sandbox activation is not production activation. `PaymentMode` remains gate-resolved (server-side); dev uses `Online` pointing at the sandbox while production keeps `ManualCoordination` until merchant setup + FIN-DEC approvals + human sign-off.
- **Manual fallback remains mandatory.** Per `mvp-scope.md` §7 and `platform-payment-adapter` AC8, the `MANUAL_COORDINATION` path (reference, proof upload, `MENUNGGU_VERIFIKASI_PEMBAYARAN`, admin verification with recent re-authentication) is implemented regardless of provider, and Step 8 is never removed.
- **`G-PAYOUT-01` stays closed.** Vendor payout remains manual (recorded transfer with proof) — SumoPod choice does not open automated settlement.

Risks / revisit criteria:

- Sandbox credentials and endpoints may change; L3 must load them from environment (secrets-managed) so a provider switch is config-only.
- Fee model (0.7% + Rp 300, QRIS 2-day settle) feeds reconciliation expectations in `platform-financial-ledger` (AC10) — L4 reconciliation must use provider settlement records and treat variance as exceptions, per that spec.
- Re-evaluate this ADR if the sandbox is withdrawn, if production provider selection lands differently, or if merchant/FIN-DEC decisions pick another provider — production provider choice is a separate decision.
