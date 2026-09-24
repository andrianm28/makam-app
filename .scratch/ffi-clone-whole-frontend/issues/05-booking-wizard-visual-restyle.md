# 05: Booking wizard visual restyle

**What to build:** A customer working through the multi-step grave-plot
booking wizard sees FFI's visual language (color, spacing, card style,
button shapes, progress/step-indicator treatment) applied to the
wizard's existing steps. FFI's real donation-checkout flow (amount +
payment-method selection) does not structurally map onto grave-plot
selection with real-time availability state, so this ticket does NOT
restructure the wizard's step sequence or its content — visual language
only, on the structure that already exists and already works.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] Every existing step of the booking wizard uses the site's
      FFI-aligned visual language (buttons, cards, spacing, typography,
      progress indicator) via this project's own existing `mk.*`
      primitives.
- [ ] The wizard's existing step sequence, field rules, validation, plot
      availability/holding behavior, and pricing display are unchanged —
      verified by a real diff review, not just "I didn't touch that
      file."
- [ ] The Floor/Block Map grave-plot picker (already redesigned in an
      earlier batch) is not reworked by this ticket — only visual
      elements this ticket actually touches change; if the picker already
      matches the target visual language, this ticket leaves it alone.
- [ ] No FFI-specific donation/checkout vocabulary or step content is
      introduced anywhere in the wizard.
- [ ] `BookingWizardRouteTest` gains real assertions for the new visual
      markers where they change; all existing behavioral assertions
      continue to pass unchanged.
- [ ] `tests/browser/e2e-booking.spec.ts`,
      `tests/browser/e2e-booking-mobile.spec.ts`, and
      `tests/browser/e2e-booking-loading-states.spec.ts` all still pass.
- [ ] `bash ci/verify-docs.sh` passes.
