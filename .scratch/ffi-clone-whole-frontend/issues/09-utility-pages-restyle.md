# 09: Small utility pages restyle (certificate status, invoice receipt, visitation, pre-need interest)

**What to build:** A customer checking a certificate's status, viewing an
invoice receipt, scheduling a visitation, or expressing pre-need interest
sees each of these four small pages restyled to the site's FFI-aligned
visual language. None has a direct FFI structural analog, so this is
visual language applied to each page's existing structure. These four
are bundled into one ticket because each is a single small page of the
same shape (a form or a status display) — splitting them into four
separate tickets would add review overhead with no independent delivery
value.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] `CertificateStatusPage`, `InvoiceReceiptPage`, `VisitationPage`, and
      `PreNeedInterestPage` all use the site's FFI-aligned visual
      language (cards, spacing, typography, buttons) via this project's
      own existing `mk.*` primitives.
- [ ] Each page's existing data display, form fields, validation, and
      submission behavior are completely unchanged — verified by a real
      diff review per page.
- [ ] No page's content or copy changes as a side effect of the restyle.
- [ ] `CertificateStatusPageTest`, `InvoiceReceiptPageTest`,
      `VisitationPageTest`, and `PreNeedInterestPageTest` gain real
      assertions for the new visual markers where they change on each
      page; all existing behavioral assertions continue to pass
      unchanged.
- [ ] `bash ci/verify-docs.sh` passes.
