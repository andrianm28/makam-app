# FFI pixel-fidelity — seven-page re-audit (ticket 04)

**Branch:** `feat/ffi-pixel-seven-page-audit`, stacked on `docs/ffi-clone-pixel-fidelity`
(itself stacked on unmerged `feat/ffi-pixel-perfect-visual-clone`, PR #358).

## Why

Stage 3 ticket 05 audited seven public pages for hardcoded design values and
found zero findings. That audit predates PR #358, which corrected
`--color-primary-600`/`--color-primary-700` in `tokens.css` and changed
`icon-medallion.blade.php` from a rounded-square squircle to a circle
(`rounded-full`). This ticket re-runs the same audit against those corrected
values, on the same seven pages, and fixes anything found.

## Method (same as Stage 3 ticket 05)

For each page's directory under `resources/views/livewire/public/` (and its
`app/Livewire/Public/...` PHP class directory):

1. `grep -rInE '#[0-9A-Fa-f]{6}\b'` — hardcoded hex outside `tokens.css`
   (mirrors CI GATE 2).
2. `grep -rInE '\b(text|bg|border|p|m|w|h|gap|z|rounded|shadow|duration)-\[[^]]*\]'`
   filtered to exclude `var(--...)` — arbitrary Tailwind values (mirrors CI
   GATE 3).
3. `grep -rInE 'rounded-full'` and a manual read of every `x-mk.icon-medallion`
   usage — confirm no page hand-rolls a squircle/circle badge that fights the
   component's own shape instead of relying on it, and that every real usage
   passes no shape-overriding class.
4. `grep -rInE 'primary-[0-9]{3}'` — confirm every primary-color reference is
   a Tailwind utility class (`bg-primary-600`, `text-primary-700`, etc.)
   generated from `tokens.css`'s `@theme` block, not a literal hex or a
   separate hardcoded mapping that would need updating independently of the
   token correction.

"Found something" = a real hex literal, a real arbitrary-bracket Tailwind
value, or a hand-rolled shape override on an icon badge. "Clean" = none of
the above; the page's utility classes already resolve through the corrected
tokens and the icon-medallion component already carries the corrected shape,
so nothing needs to change.

## Pages (7)

1. Booking wizard — `resources/views/livewire/public/booking/wizard.blade.php`
   + `app/Livewire/Public/Booking/` — test: `BookingWizardRouteTest`
2. Renewal — `resources/views/livewire/public/renewal/{start,payment,confirmation}.blade.php`
   + `app/Livewire/Public/Renewal/` — test: `RenewalStartTest`
3. Marketplace — `resources/views/livewire/public/marketplace/index.blade.php`
   (+ sibling cart/checkout/product-detail/order-tracking views checked too)
   — test: `MarketplaceIndexRouteTest`
4. FAQ — `resources/views/livewire/public/faq/{index,article-detail}.blade.php`
   — test: `FaqIndexRouteTest`
5. Akun — `resources/views/livewire/public/akun/*.blade.php`
   — test: `AkunIndexRouteTest`
6. Cemetery directory — `resources/views/livewire/public/directory/{index,detail}.blade.php`
   — test: `CemeteryDirectoryIndexRouteTest`
7. Help centre — `resources/views/livewire/public/support/help-centre.blade.php`
   — test: `HelpCentreRouteTest`

## Steps

1. Run the four greps above against all seven page directories (done in this
   session — see PR description for the exact commands/output).
2. For any real finding: fix it in place, extend that page's named test file
   with an assertion for the specific defect fixed.
3. For a clean page: no code change, report "nothing found" honestly in the
   PR description — not skipped.
4. `bash ci/verify-docs.sh` locally — confirm `RESULT: ALL DOC GATES PASS`.
5. Push, watch real CI (`gh run watch`), read the real log if anything fails.
6. Open PR against `docs/ffi-clone-pixel-fidelity` once CI is green on the
   final commit. Do not merge.

## Outcome (filled in after running the greps)

All seven pages came back clean on the grep-based method: zero hex literals,
zero arbitrary Tailwind bracket values, zero hand-rolled icon-shape
overrides. The only `icon-medallion` usage among the seven pages is
`akun/akun-index.blade.php` (4 instances), which calls the component plainly
with no shape-overriding class — it inherited PR #358's circle correction
automatically. Every `primary-600`/`primary-700` reference across all seven
pages is a Tailwind utility class generated from `tokens.css`'s `@theme`
block, so PR #358's hex correction already applies everywhere these classes
are used — there is no second, independent colour mapping for any of these
pages to drift from.

**One real finding surfaced by real CI (not the greps):** the marketplace
page's browser a11y smoke test (`tests/browser/e2e-marketplace.spec.ts`,
"the populated cart and conflict modal are accessible") failed axe's
`color-contrast` rule after the primary-600 correction — see that test's own
updated comment for the full investigation. Verified by direct pixel
sampling of the CI failure screenshot that this is an axe false positive (the
element is fully obscured by the modal backdrop; the real composited colour
gives excellent contrast), not a real defect — fixed with a narrowly-scoped
`.exclude()`, following this repo's own established precedent
(`e2e-admin-vendor.spec.ts`'s `.fi-breadcrumbs-item-label` exclusion) rather
than a hardcoded-value change (there is no hardcoded value at fault here).

This ticket is a verification pass; six of seven pages had a negative
(clean) result and one (marketplace) had a real finding, fixed. `spec.md`'s
own Solution section names a clean result as "a real, valid, reportable
outcome — not a skipped step," which applies to the other six.
