# 06: Renewal flow visual restyle

**What to build:** A customer starting or completing a grave-lease
renewal sees the same FFI-aligned visual-language treatment as the
booking wizard (ticket 05) applied to the renewal flow's existing start,
payment, and confirmation screens. No FFI structural equivalent exists
for a renewal/subscription concept, so this is visual language applied
to the existing flow — its payment handling, quoting, and confirmation
behavior are completely unchanged.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] The renewal start, payment, and confirmation screens all use the
      site's FFI-aligned visual language (buttons, cards, spacing,
      typography) matching the booking wizard's own restyle (ticket 05)
      so both flows feel like the same product.
- [ ] The renewal flow's existing quoting, payment-session handling
      (including the existing guard against the hourly order-expiry sweep
      firing mid-payment), and confirmation/notification behavior are
      completely unchanged — verified by a real diff review.
- [ ] The external-renewal path (marked-external renewals, distinct from
      the online-payment path) keeps its own existing behavior unchanged.
- [ ] `RenewalStartTest`, `RenewalPaymentTest`, and
      `RenewalConfirmationTest` gain real assertions for the new visual
      markers where they change; all existing behavioral assertions
      continue to pass unchanged.
- [ ] `tests/browser/e2e-renewal.spec.ts` and
      `tests/browser/e2e-renewal-external.spec.ts` both still pass.
- [ ] `bash ci/verify-docs.sh` passes.
