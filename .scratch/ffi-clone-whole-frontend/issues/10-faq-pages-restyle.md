# 10: FAQ pages restyle

**What to build:** A visitor with a question sees the FAQ index and
article detail pages cloned to FFI's real FAQ-accordion and static-page
pattern — the strongest available structural analog, confirmed directly
against FFI's own source — using Makam's own existing FAQ content
verbatim.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] The FAQ index page's accordion/list treatment matches FFI's real
      `FAQAccordion` pattern (spacing, typography, expand/collapse
      affordance) via this project's own existing `mk.*` primitives.
- [ ] The FAQ article detail page's layout matches FFI's real static-page
      pattern.
- [ ] All existing FAQ content, article copy, and category grouping are
      unchanged — no content is translated from FFI's own copy.
- [ ] `FaqIndexRouteTest` and `FaqArticleDetailRouteTest` gain real
      assertions for the new visual markers where they change; all
      existing behavioral assertions (including the two-method fix from
      the prior merge-conflict resolution) continue to pass unchanged.
- [ ] `tests/browser/e2e-faq.spec.ts` still passes.
- [ ] `bash ci/verify-docs.sh` passes.
