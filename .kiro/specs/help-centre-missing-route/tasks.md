# Tasks — `/bantuan` had no route

`_Requirements: N_` references the numbered statements in [`bugfix.md`](bugfix.md): 1 = current behaviour, 2 = expected behaviour, 3–6 = unchanged behaviour (regression prevention).

- [x] Build `App\Livewire\Public\Support\HelpCentre` (PUB-060) — static, no domain read, contact data read from `ContactInfo`/`CompanyInfo` only. _Requirements: 2_ — done 8 Aug 2026 (Batch 0), CI green (run `31237318086`, job `93052168835`)
- [x] Register `Route::get('/bantuan', HelpCentre::class)->name('bantuan.index')` in `routes/web.php`. _Requirements: 1, 2_ — done 8 Aug 2026
- [x] Invert `FooterLegalLinksRouteTest`'s test that pinned the old 404 into a positive regression check; keep its history visible rather than deleting it. _Requirements: 3_ — done 8 Aug 2026, renamed to `test_bantuan_link_from_the_footer_reaches_the_real_help_page`
- [x] Write property tests proving: the route resolves with the correct name and URI, the page states channels/hours/emergency-disclaimer, no SLA/24-7 claim, works without JavaScript, and the placeholder-data admission is present. _Requirements: 2, 4_ — done 8 Aug 2026, `tests/Feature/Livewire/Public/Support/HelpCentreRouteTest.php` (15 methods)
- [x] Fix `home-page.blade.php`'s `tel:` link, found while building this fix — stripping the leading `+` dials a domestic number on a handset instead of the intended international one. _Requirements: 5_ — done 8 Aug 2026
- [ ] Assign feature-spec ownership for PUB-060's acceptance criteria, or record that it deliberately has none. — **not done, not this spec's to decide** — see `docs/domain/traceability-matrix.md` §E

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) §6.5, §6.10 and [`resources/css/tokens.css`](../../../resources/css/tokens.css). Rule: never hardcode a hex, px, ms, or shadow; never use a Tailwind arbitrary value.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Emergency disclaimer | `<x-mk.alert intent=urgent>` | `--mk-intent-urgent-*`, placed above the channel list |
| Contact channel links | `<x-mk.button variant=secondary>` | `--mk-control-h-md`, `tel:`/`mailto:` hrefs |
| Not-yet-active notice | `<x-mk.alert intent=info>` | `--mk-intent-info-*` |
| Onward links (FAQ, home) | `<x-mk.button variant=secondary>` | `--color-primary-600` border |

### Required UI states

This screen is deliberately static (no domain read, no form), which is itself the honest state for §6.5 "provider unavailable": it is the page other screens send a user to precisely because it has nothing that can fail. §6's loading, validation-error, and provider-unavailable states are therefore not reachable here by design, not by omission — see `HelpCentre`'s own doc block. What does apply:

| State | Notes |
|---|---|
| support (§6.10) | this screen *is* the escape hatch — persistent header link plus this page |
| duplicate/retry-safe (§6.6) | static page, idempotent by construction — no action to duplicate |
| responsive (§4.3) | not verified on this host — no browser; see `makam-verify` |

### Tasks

- [x] Reference tokens for all colour/spacing/type; zero hardcoded values — CI-enforced (`ci/verify-docs.sh`), green on Batch 0
- [x] Emergency disclaimer renders above the channel list, not after — `HelpCentreRouteTest::test_emergency_disclaimer_appears_before_the_contact_channels`
- [x] No SLA, response-time, or 24/7 claim anywhere on the page — `HelpCentreRouteTest::test_help_centre_does_not_promise_a_response_time_or_sla`, `::test_help_centre_does_not_claim_round_the_clock_availability`
- [ ] Verify accessibility (design-system.md §7): 44 px targets, focus ring, keyboard-only path — **NOT TESTED**, no browser on this host
