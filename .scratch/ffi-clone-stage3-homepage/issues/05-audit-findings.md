# 05 audit findings — visual-consistency audit, remaining public pages

Real audit run against commit 3b85bad5 (docs/ffi-clone-stage3-homepage tip),
covering every Blade view under the 7 page domains ticket 05 names: booking
wizard, renewal (start/payment/confirmation), marketplace (index/cart/
checkout/product-detail/order-tracking), FAQ (index/article-detail), akun
(index/draft-list/order-list/renewal-list/document-list), cemetery directory
(index/detail), help centre.

## Checks run (same patterns `ci/verify-docs.sh` GATE 2/3/11 use)

```bash
grep -rInE '#[0-9A-Fa-f]{6}\b' --include='*.blade.php' <the 7 domains>
grep -rInE '\b(text|bg|border|p|m|w|h|gap|z|rounded|shadow|duration)-\[[^]]*\]' \
  --include='*.blade.php' <the 7 domains> | grep -v 'var(--'
grep -nE 'z-index\s*:\s*[0-9]' --include='*.blade.php' <the 7 domains>
grep -n 'tone="earth"\|tone="leaf"' <the 7 domains>
grep -n 'bg-white\|text-white\b' <the 7 domains>
```

## Result: zero findings on every page, every check

| Page | Hardcoded hex | Arbitrary Tailwind (no token) | Raw z-index | Stale tone | Literal white |
|---|---|---|---|---|---|
| Booking wizard | none | none | none | none | none |
| Renewal (3 files) | none | none | none | none | none |
| Marketplace (5 files) | none | none | none | none | none |
| FAQ (2 files) | none | none | none | none | none |
| Akun (5 files) | none | none | none | none | none |
| Cemetery directory (2 files) | none | none | none | none | none |
| Help centre | none | none | none | none | none |

`bash ci/verify-docs.sh` run repo-wide: `RESULT: ALL DOC GATES PASS` (all 19
gates), confirming the above isn't a scoped false negative.

Component-library usage across these 19 files, confirming Stage 1/2's rebase
already reaches them (not just an absence of violations, but active use of
the updated system): 164 `<x-mk.button>`, 103 `<x-mk.alert>`, 76
`<x-mk.card>`, 73 `<x-mk.badge>`, 15 `<x-mk.field>`, 13
`<x-mk.filter-chip>`, 11 `<x-mk.spinner>`, 9 `<x-mk.gate-closed-page>`, 8
`<x-mk.table>`, 5 `<x-mk.gate-closed-banner>`, 4 `<x-mk.stepper>`, 4
`<x-mk.modal>`, 4 `<x-mk.icon-medallion>` (all already on the current
`primary`/`secondary`/`brand` tone values from Stage 2's icon-medallion tone
rename — zero `earth`/`leaf` remaining, confirmed above).

## Conclusion

Stage 1's `tokens.css` value rebase (unchanged names) and Stage 2's
`x-mk.*` component updates already reach all 7 pages this ticket names, with
no code-level straggler found. This ticket's real job — confirming that,
per page, with real evidence — is complete. No code change was made: there
was nothing to fix. Per `ci/verify-docs.sh`'s own continuous enforcement
(GATE 2/3/11 run on every push, not just this audit), this state is also
guarded against regressing without this ticket needing to add a new,
narrower test of its own.
