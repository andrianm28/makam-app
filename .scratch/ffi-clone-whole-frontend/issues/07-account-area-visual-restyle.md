# 07: Account area visual restyle

**What to build:** A logged-in customer viewing their account area
(order history, care-subscription history, settings) sees FFI's real
dashboard/list visual language (confirmed analog: FFI's `/akun` +
`/donasi-saya`) applied to the account area's existing tabs and list
structure. This ticket does not touch the memorial/QR check-in module
(covered separately by ticket 08) or any account settings behavior.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] The account index (order history, tabs, settings) uses the site's
      FFI-aligned dashboard/list visual language via this project's own
      existing `mk.*` primitives.
- [ ] The care-subscription history view uses the same visual language as
      the rest of the account area.
- [ ] All existing account behavior — order history data, settings
      fields, session/auth gating — is completely unchanged.
- [ ] This ticket does not modify the memorial/QR check-in module's own
      pages (`MemorialFamilyPage`/`MemorialPublicPage`) — that is ticket
      08's scope, kept separate because the public memorial page has its
      own distinct imagery consideration.
- [ ] `AkunIndexRouteTest` and `CareHistoryPageRouteTest` gain real
      assertions for the new visual markers where they change; all
      existing behavioral assertions continue to pass unchanged.
- [ ] `tests/browser/e2e-akun.spec.ts` still passes.
- [ ] `bash ci/verify-docs.sh` passes.
