# ADR-0036: Add SumoPod's Production/Live Tier as a Second Configurable Provider

- **Status:** Accepted (user-approved 21 Aug 2026, bounded-path brainstorming)

## Context

ADR-0033 chose SumoPod's sandbox as the dev/staging payment provider. Until this ADR, `PaymentProviders` named exactly one slug (`sumopod-sandbox`) and its own doc block said plainly: "Production activation is a separate decision... so no production slug exists." Nothing in this codebase — not a config stub, not a disabled code path — could ever route a payment through anything but SumoPod's test environment, regardless of `G-PAY-01`'s state.

The user confirmed (21 Aug 2026) they intend to use SumoPod's own production/live tier for real production payments (not a different vendor), and that SumoPod's live API uses the **same contract** as its sandbox — same `POST /api/v1/payments` endpoint shape, same `X-Api-Key` auth header, same webhook envelope and signature scheme — differing only in base URL and credentials.

This was classified as a **bounded** task under `superpowers:brainstorming` (a well-scoped extension of an already-generic, already-provider-agnostic mechanism — `SumoPodPaymentClient::fromConfig()` and `SumoPodWebhookSignature::fromConfig()` both already resolved `payment.providers.{payment.default}` generically, with no code hardcoded to the sandbox slug), not a full architectural rebuild — confirmed by reading both `fromConfig()` implementations before deciding scope, not assumed.

## Decision

**Add `PaymentProviders::SUMOPOD_LIVE = 'sumopod-live'`** as a second provider slug, with a matching `payment.providers.sumopod-live` block in `config/payment.php`, sourced from new, correctly-spelled environment variables:

- `SUMOPOD_LIVE_BASE_URL` — no default (empty fails closed, unlike the sandbox block's hardcoded default URL — there is no safe default production endpoint to bake in).
- `SUMOPOD_LIVE_API_KEY` — empty by default, same fail-closed posture as `SUMODOP_SANDBOX_API_KEY`.
- `SUMOPOD_LIVE_WEBHOOK_SECRET` — comma-separated rotation-capable list, same shape as the sandbox's `SUMODOP_SANDBOX_WEBHOOK_SECRET`.

**On spelling:** unlike `SUMODOP_SANDBOX_API_KEY` (ADR-0033 §Credential preserves that exact typo because it already matched a real host injection), these are brand-new variable names with no prior injection to stay byte-compatible with — they are spelled correctly (`SUMOPOD_LIVE_*`, not `SUMODOP_LIVE_*`).

**No client code changes.** `SumoPodPaymentClient::fromConfig()` and `SumoPodWebhookSignature::fromConfig()` both already resolve `config('payment.providers.{payment.default}')` generically — selecting the new slug via `PAYMENT_PROVIDER=sumopod-live` (or `payment.default`) is the whole activation mechanism for the provider layer. This was verified by reading both classes' resolution code before writing any implementation, not assumed from their doc comments.

**Scope boundary — this does NOT open `G-PAY-01`.** Configuring live credentials makes production payment processing *possible*; it does not make it *active*. `G-PAY-01` stays closed in production until an admin, with a fresh re-authentication, records real activation evidence through the Feature Gate admin panel (`GuardPaymentSession`'s condition 1) — a deliberate, separate, audited human action this ADR does not perform and cannot substitute for. This ADR is purely the provider-configuration prerequisite; ADR-0033's own §Consequences already named "G-PAY-01 stays closed for production" as unchanged by a provider choice, and this ADR does not revise that.

## Consequences

What this unblocks:

- A real business decision to go live is no longer blocked on "there is nowhere to put production credentials" — that configuration surface now exists, mirroring the sandbox block's exact shape so no downstream code (guard, checkout client, webhook receiver) needs to know which tier is active.
- `BookingWizardOnlinePaymentTest::test_the_sandbox_warning_does_not_show_for_a_non_sandbox_provider` now exercises a real production slug instead of a placeholder string (`'some-future-production-provider'`), closing a forward-looking gap that test's own comment already anticipated.

What this does **not** change:

- **`G-PAY-01` stays closed for production**, exactly as ADR-0033 already established — see the Decision section's explicit scope boundary.
- **Manual fallback remains mandatory**, unchanged from ADR-0033.
- **`G-PAYOUT-01` stays closed** — vendor payout remains manual, unaffected by this provider addition.
- **No new merchant/`badan_usaha` binding path.** Condition 6 of the six-condition guard is unchanged — it still resolves `PAYMENT_MERCHANT_REF`/`PAYMENT_BADAN_USAHA_REF` (or their `site_settings` overrides) independently of which provider tier is selected.

Risks / revisit criteria:

- **Real production credentials must never be typed into a chat session or committed to this repository.** They belong in the deployment host's protected env injection (the same `/opt/makam/compose/` pattern ADR-0033 established for sandbox), set by a human working directly on the host.
- If SumoPod's live API turns out to diverge from the sandbox contract in a way not caught during this ADR's research (a different auth mechanism, different response fields, a different webhook envelope), `SumoPodPaymentClient`/`SumoPodWebhookSignature` would need real code changes, not just new config values — re-evaluate this ADR's "same contract" premise against SumoPod's actual production API documentation before the first real credential is provisioned.
- Re-evaluate if the production provider decision changes to a different vendor entirely — this ADR is specific to SumoPod's own live tier, not a generic "any production provider" mechanism.
