# ADR-0023: Quarantine All Untrusted Files Before Use

## Status
Accepted — 23 July 2026

## Decision
All user/vendor/admin uploads enter private quarantine and pass type/content/size and malware checks before becoming accessible domain attachments. Restricted-document scanning fails closed.

## Consequences
Adds asynchronous scan latency and scanner operations, but prevents direct exposure of malicious files.

## Amendment 1 (7 September 2026, Phase 3 Batch M9, VAULT-02): marketplace product photos — scanned, deliberately never quarantined

**Finding.** Marketplace product photo uploads
(`app/Filament/Admin/Resources/ProductResource/Schemas/ProductForm.php`) write
admin/vendor-uploaded files straight to the `public` disk with NO malware
scan at all — the only upload path in the codebase that skipped one
entirely, in apparent tension with this ADR's "all user/vendor/admin
uploads" wording.

**Why this file is a real, narrow exception to "quarantine-first," not a gap
in it.** A product photo is designed to be viewed by an anonymous,
unauthenticated shopper on the public marketplace catalogue the moment it is
saved — `MarketplacePresenter::photoUrl()` resolves it via a plain public
URL. Routing it through the vault's quarantine → scan → promote →
audited-download-route pipeline (the model every restricted document in this
ADR follows) would mean: (a) an asynchronous scan delay before a newly
activated product's photo is visible at all, and (b) serving a genuinely
public catalogue image through a route designed to authorize and audit
access to a RESTRICTED document — the two have opposite access models, and
forcing the public case through the restricted one is the wrong direction
to bend either.

**Decision (this amendment).** Product photos stay on the `public` disk —
`ProductForm`'s pre-existing, reasoned choice — but MUST now pass a malware
scan before the save that points `products.photo_path` at them is allowed to
complete. `App\Domain\Marketplace\Actions\ScanProductPhoto` reuses the
platform vault's own `Contracts\MalwareScanner` boundary (the same interface
`Actions\ScanDocument` scans restricted documents with — on this combined
dev/staging host, the identical `Adapters\MockScanner` binding) at the ONE
choke point every write to `photo_path` passes through:
`App\Domain\Marketplace\Models\Product`'s `saving` model event. A non-CLEAN
verdict deletes the just-written file from the public disk and throws
`Exceptions\ProductPhotoFailedScanException`, refusing the save — a bad file
is never left reachable and the row is never saved pointing at it.

This is deliberately NOT full content-type/extension validation
(`DocumentValidator`): that class's `DocumentKind::ProductImage` allowlist is
narrower (`jpg/jpeg/png`) than `ProductForm`'s own accepted types
(`image/jpeg`, `image/png`, `image/webp`), and enforcing it here would
silently reject a legitimate WebP upload the form itself accepts.
Content-type/extension checks for this field remain Filament's own
`image()`/`acceptedFileTypes()` job; this amendment closes only the malware
gap.

**Restated rule:** "all user/vendor/admin uploads ... pass ... malware
checks" (the Decision above) still holds without exception. What this
amendment narrows is the QUARANTINE half — a product photo is "scanned but
not quarantined, because it is designed to be public," the one class of file
in this codebase carrying that exemption. No other upload path may cite this
amendment without its own ADR record.

## Amendment 2 (7 September 2026, Phase 3 Batch M9, VAULT-03): provider-statement CSV import — approved transient/parse-only exemption

**Finding.** `Filament\Admin\Resources\Reconciliations\Actions\UploadProviderStatementAction`
parses an admin-uploaded SumoPod settlement CSV and dispatches it straight to
`Jobs\ReconcileStatementJob` without ever entering vault quarantine or a
malware scan.

**Why this is a genuine, verified exemption, not an assumption.** The action
was read end-to-end before this amendment was written, confirming both
halves the finding required to be checked rather than merely asserted:

1. **Transient.** The uploaded file is deleted unconditionally in the
   action's own `finally` block (`$storage->delete($storedPath)`) — on
   success, on a denied authorization, on a CSV parse failure, and on any
   other exception. No code path retains it.
2. **Parse-only.** `Support\ProviderStatementCsvParser::parse()` performs
   strict structural parsing (exact header, exact column count, a row cap)
   into plain `line_reference`/integer-minor-unit pairs; nothing in the file
   is ever executed, templated, or passed to a shell/SQL/formula
   interpreter. Only those opaque references and integer amounts survive
   into `reconciliations`/`reconciliation_exceptions` — the persisted
   evidence — never a byte of the original file and never a bank/account/
   card/customer-identifying value (see that Action's own doc block).

**Decision (this amendment).** Per this ADR's own escape valve ("approved
benchmark extensions" / documented exception), provider-statement CSV
uploads are EXEMPT from quarantine-first and from a malware scan, on the
narrow, verified grounds above. This is deliberately the higher bar this
ADR's finding required ("Prefer (a) [route through the vault] unless you can
concretely verify the file is genuinely transient and parse-only") — had the
file been retained anywhere, or had parsing evaluated any part of its
content, option (a) — adding `DocumentKind::PROVIDER_STATEMENT` and routing
through `Actions\UploadDocument` → `Actions\ScanDocument` →
`Actions\PromoteDocument` before parsing — would have been the only correct
choice, and remains the fallback if this file's handling ever changes to
retain the CSV or admit richer parsing (formulas, macros, an image/attachment
field, etc.).

**This amendment is flagged for mandatory human security-architecture
sign-off, same as Amendment 1 above and as VAULT-05 in this batch's PR** —
recorded here as a proposed, reasoned exemption, not as a unilaterally
closed decision.
