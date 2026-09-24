# 11: Static informational pages restyle (help centre + legal)

**What to build:** A visitor looking for support, or reading the privacy
policy or terms of service, sees the help centre and legal pages cloned
to FFI's real static-page pattern — a strong, direct structural analog
confirmed against FFI's own source — using Makam's own existing help and
legal content verbatim. These three pages are bundled into one ticket
because all three are the same trivial static-content-page shape;
splitting them further would add review overhead with no independent
delivery value.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] The help centre, privacy policy, and terms of service pages all use
      the same restyled static-page shell (spacing, typography, heading
      hierarchy) matching FFI's real static-page pattern.
- [ ] All existing help, privacy, and terms content is unchanged — no
      content is translated from FFI's own copy, and no legal text is
      altered as a side effect of a visual restyle.
- [ ] The footer's existing legal links continue to point at the same
      real destinations as before.
- [ ] `HelpCentreRouteTest`, `PrivacyPolicyRouteTest`,
      `TermsOfServiceRouteTest`, and `FooterLegalLinksRouteTest` gain real
      assertions for the new visual markers where they change; all
      existing assertions continue to pass unchanged.
- [ ] `bash ci/verify-docs.sh` passes.
