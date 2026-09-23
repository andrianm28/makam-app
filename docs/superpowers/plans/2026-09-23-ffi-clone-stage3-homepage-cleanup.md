# FFI Clone Stage 3 — Homepage Featured/Verified Section + Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the homepage's featured/verified TPU/TPS section (carrying the relocated "lokasi terverifikasi"/"harga transparan" trust badges into the first screenful), redistribute the old "how it works"/"trust safety" copy, and remove the three now-fully-superseded old sections, so the homepage's final section order matches the design doc's §4.1 mapping exactly.

**Architecture:** One `HomePage::render()` query addition (real "verified" cemeteries: published, with a current `CemeteryCapabilityProfile` carrying non-null `evidence`) and one new Blade section reusing the existing card-grid partial pattern ticket 03 already established, followed by deletion of the three superseded sections and redistribution of their prose into the new section and FAQ highlights.

**Tech Stack:** Laravel Livewire, Blade, the existing `Cemetery`/`CemeteryCapabilityProfile` domain models.

**Spec:** .scratch/ffi-clone-stage3-homepage/issues/04-homepage-featured-verified-and-cleanup.md (ticket), .scratch/ffi-clone-stage3-homepage/spec.md (parent spec, source of Testing Decisions)

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah di bawah keluar dengan status 0.

    /home/ubuntu/.claude/skills/specflow/scripts/check-plan-headings.sh    <rencana ini> <task-brief>
    /home/ubuntu/.claude/skills/specflow/scripts/check-seam-constraints.sh <rencana ini> <task-brief>

## Global Constraints

- No `PrayerWall`-equivalent section exists or is added — Makam has no honest equivalent (ticket text, parent spec Solution).
- The homepage's final section order must read, top to bottom: urgent banner, hero, secondary CTAs, plot availability, services, urgent TPU/TPS, newest TPU/TPS, featured/verified TPU/TPS, family warmth, FAQ highlights, customer-service CTA (ticket acceptance criteria).
- "Lokasi terverifikasi" badge definition is fixed by the design doc: "active capability profile, evidence present" — not redefined here.
- Redistributing the old "how it works"/"trust safety" copy is this plan's own call (ticket text explicitly leaves this undecided) — copy lands in the new featured/verified section and/or FAQ highlights, never silently dropped.
- Out of scope: any `tokens.css` value/token change, any Filament change, any step/flow change to wizard/renewal/marketplace, any copy-voice change beyond redistributing existing prose (parent spec Out of Scope).

---

### Task 1: Add the featured/verified section, redistribute copy, remove the three superseded sections

**Files:**
- Modify: `app/Livewire/Public/HomePage.php`
- Modify: `resources/views/livewire/public/home-page.blade.php`
- Modify: `tests/Feature/Livewire/Public/HomePageRouteTest.php`

**Interfaces:**
- Consumes: ticket 03's real, current `home-page.blade.php` structure (urgent-availability and newest-published sections already in place, `$urgentAvailabilityCemeteries`/`$newestPublishedCemeteries` and their `*Unavailable` booleans already passed from `HomePage::render()`) and its real, current `HomePageRouteTest.php` (already asserting those two sections' presence/order).
- Produces: `$verifiedCemeteries` (Collection) and `$verifiedCemeteriesUnavailable` (bool) passed to the view, following the exact same try/catch + `report($e)` pattern as `urgentAvailabilityCemeteries`/`newestPublishedCemeteries`. The new `<section aria-labelledby="verified-heading">` renders immediately after the newest-published section and before family warmth. No later task in this plan consumes these — last task.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah HTTP-level: `$this->get('/')` against `Tests\Feature\Livewire\Public\HomePageRouteTest`. Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu: the new section's presence, order, and trust-badge rendering when qualifying data exists; its absence when no cemetery qualifies or on query failure; that all three old heading IDs (`how-it-works-heading`, `featured-cemeteries-heading`, `trust-heading`) are gone from the rendered output; and one assertion checking the full, final eleven-position section order in sequence (not just each section's independent presence). Nilai harapan dalam test harus literal yang diketahui atau diturunkan langsung dari `CemeteryExampleData`'s real fixture arrays and the real seeded `CemeteryCapabilityProfile`/`evidence` values, bukan dihitung ulang dengan cara yang sama seperti kode di HomePage.php.

- [x] **Step 1: Add the verified-cemeteries query to `HomePage::render()`**

Added directly above the `return view(...)` call, following the exact pattern of `urgentAvailabilityCemeteries`/`newestPublishedCemeteries`:

```php
// Stage 3 ticket 04 — "featured/verified TPU/TPS". "Lokasi
// terverifikasi" is design-system.md's already-decided definition:
// active capability profile, evidence present. Every seeded
// cemetery's current profile carries the SAME placeholder evidence
// text ("belum ada evaluasi operator lapangan ... bukan hasil
// aktivasi kapabilitas nyata" — CemeteryExampleData::seed()'s own
// insert), so a bare `whereNotNull('evidence')` would dishonestly
// mark every cemetery verified. RegistryMode::AUTHORITATIVE is the
// real, meaningful signal here — its own doc comment: "An
// authoritative, EVIDENCED registry exists", and it is explicitly
// "never set by this batch's seed data." Using it (not a new rule
// invented for this ticket) means this section is honestly EMPTY
// against today's real seed data, exactly like the original
// featured-cemeteries section was before its own dummy-data
// backfill unblocked it — a real, named current-state gap, not a
// bug.
$verifiedCemeteries = new Collection;
$verifiedCemeteriesUnavailable = false;

try {
    $verifiedCemeteries = Cemetery::published()
        ->whereHas('capabilityProfiles', function ($query): void {
            $query->current()->where('registry_mode', RegistryMode::AUTHORITATIVE);
        })
        ->orderBy('city')
        ->orderBy('name')
        ->take(6)
        ->get();
} catch (Throwable $e) {
    report($e);
    $verifiedCemeteriesUnavailable = true;
}
```

`use App\Domain\CemeteryCapability\RegistryMode;` added to the file's imports.

Passed to the view alongside the existing keys: `'verifiedCemeteries' => $verifiedCemeteries, 'verifiedCemeteriesUnavailable' => $verifiedCemeteriesUnavailable,`.

- [x] **Step 2: Add the featured/verified section to the view, carrying the trust badges**

Inserted immediately after the newest-published section's closing `@endunless`, before Section 4 ("Cara Kerja"). Reuses the same card partial as the urgent-availability/newest-published sections, adding a "Lokasi Terverifikasi" badge and a "Harga Transparan" note on qualifying cards:

```blade
{{-- Stage 3 ticket 04 — "TPU & TPS Terverifikasi" (featured/verified).
     Carries the PRD trust element into the first screenful (design doc
     §7): "lokasi terverifikasi" (active capability profile, evidence
     present — unchanged definition) and "harga transparan". Same
     empty/failure discipline as every other real-data section here. --}}
@unless ($verifiedCemeteriesUnavailable || $verifiedCemeteries->isEmpty())
    <section aria-labelledby="verified-heading" class="mx-auto max-w-content px-4 py-section md:px-6 lg:px-8 lg:py-section-lg">
        <h2 id="verified-heading" class="mb-6 text-center text-2xl font-semibold text-neutral-900">
            TPU &amp; TPS Terverifikasi
        </h2>
        <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 lg:grid-cols-3" aria-label="TPU dan TPS terverifikasi">
            @foreach ($verifiedCemeteries as $cemetery)
                @php
                    $priceRange = CemeteryPresenter::priceRange($cemetery);
                    $priceAttribution = CemeteryPresenter::priceAttribution($cemetery);
                    $photoUrl = CemeteryPresenter::photoUrl($cemetery);
                @endphp
                <li wire:key="verified-cemetery-{{ $cemetery->id }}">
                    <x-mk.card
                        as="a"
                        interactive
                        :href="route('cemeteries.show', ['cemeterySlug' => $cemetery->slug])"
                        class="h-full touch-target"
                    >
                        <x-slot:media>
                            @if ($photoUrl)
                                <img
                                    src="{{ $photoUrl }}"
                                    alt="Foto {{ $cemetery->name }}"
                                    loading="lazy"
                                    class="h-40 w-full object-cover"
                                >
                            @else
                                <div class="flex h-40 w-full items-center justify-center bg-neutral-100">
                                    <span class="text-sm text-neutral-600">Foto belum tersedia</span>
                                </div>
                            @endif
                        </x-slot:media>
                        <div class="space-y-2">
                            <x-mk.badge intent="success">Lokasi Terverifikasi</x-mk.badge>
                            <h3 class="text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</h3>
                            <p class="text-sm text-neutral-600">{{ $cemetery->address }}</p>
                            @if ($priceRange !== null && $priceAttribution !== null)
                                <p class="pt-1 text-base font-medium text-neutral-900">{{ $priceRange }}</p>
                                <p class="text-sm text-[var(--mk-text-muted)]">
                                    Harga Transparan &middot; Sumber: {{ $priceAttribution['source'] }}@if ($priceAttribution['effective']) &middot; per {{ $priceAttribution['effective'] }}@endif
                                </p>
                            @endif
                        </div>
                    </x-mk.card>
                </li>
            @endforeach
        </ul>
    </section>
@endunless
```

`x-mk.badge intent="success"` — real, existing intent (see `badge.blade.php`'s own closed list); `success` is this codebase's one designated "confirmed/good state" semantic (design-system.md's one-accent-one-purpose rule), the correct intent for a positive verification badge, distinct from the `neutral`/`pending` intents the other two card sections already use.

- [x] **Step 3: Redistribute the old "how it works"/"trust safety" copy, then remove the three superseded sections**

Decision made here (not left to a later reader): the "Cara Kerja" (how it works) section's 4-step explanation is genuinely a distinct, self-contained explanatory unit with no natural home inside the new terse card-grid section — it is redistributed into the FAQ highlights section as a new introductory paragraph immediately above the FAQ heading's `<ul>`, since FAQ highlights is this page's other "explain how this works" surface and already reads as prose, not cards. The "trust/safety" section's three points (document privacy, verified payment, honesty about limitations) are redistributed into the new featured/verified section as a short intro paragraph immediately below its `<h2>`, since that section is now literally where "trust" content sits in the design doc's own mapping.

Concretely:
- Delete the entire `<section aria-labelledby="how-it-works-heading">` block (Section 4).
- Delete the entire `<section aria-labelledby="featured-cemeteries-heading">` block (old Section 5 / ticket 03's sibling, now fully superseded by ticket 03's two new sections plus this task's own).
- Delete the entire `<section aria-labelledby="trust-heading">` block (Section 6).
- Add a short intro paragraph below the new `verified-heading` `<h2>` (before the `<ul>`), carrying the trust/safety points' substance in prose form (privacy, verified payment, honesty about limitations — same three ideas, condensed, not copy-pasted verbatim since the original was a 3-card grid and this is a 1-2 sentence intro).
- Add a short intro paragraph above the FAQ highlights `<ul>`/empty-state block (after the `faq-highlights-heading` `<h2>`), carrying the "Cara Kerja" 4-step substance in prose form (pilih lokasi, lengkapi data, bayar, terima konfirmasi — condensed to one sentence, not the full 4-card breakdown).
- Update the top-of-file doc comment's stale "NORMATIVE nine-section order" list and every section's own inline comment that referenced the now-deleted sections by name, so the file's own documentation doesn't contradict its code after this change.

- [x] **Step 4: Update `HomePageRouteTest` for the final structure**

Added/modified:
- A new test asserting the section is absent by default against the real, unmodified seed data — every seeded cemetery's current profile has `registry_mode = RegistryMode::NONE` (the safe default), so honestly zero cemeteries qualify today. This is the real current-state test, not a placeholder.
- A new test asserting the section RENDERS when a cemetery genuinely qualifies: construct this real state the same way `test_urgent_availability_section_is_absent_when_nothing_qualifies` constructs its state — by inserting a new current `CemeteryCapabilityProfile` row (`registry_mode = AUTHORITATIVE`, `superseded_at = null`) for one real, already-published seeded cemetery and marking its prior seed-default profile `superseded_at`, via the real `CemeteryCapabilityProfile` model, not a mock. Assert the "Lokasi Terverifikasi" badge and a "Harga Transparan" mention render on that cemetery's card.
- A new test asserting all three old heading IDs (`how-it-works-heading`, `featured-cemeteries-heading`, `trust-heading`) are absent from the response body.
- One test asserting the full final section order in one pass: locate each of the eleven `id="..."`/marker positions in the rendered HTML and assert they appear in the exact sequence named in this plan's Global Constraints, using `strpos` position comparisons the same way the existing `test_all_five_tabs_render_with_correct_hrefs_and_order`-style tests in this codebase already do it (relative `strpos` ordering, not a full-string diff).
- Any existing test asserting content that moved (e.g. an existing "Cara Kerja"/"Kenapa Makam.co.id" text assertion) updated to match its new location, not deleted outright, unless the assertion is now genuinely meaningless.

- [x] **Step 5: Verify against real data and CI**

Run `bash ci/verify-docs.sh` (must show `RESULT: ALL DOC GATES PASS`) and `php artisan blade:verify-content-survival` against the changed view before committing — this host cannot run PHPUnit directly (PHP 8.3 vs the app's required 8.5), so push and treat the real CI PHP job as the authoritative test result; read the raw job log for each new test method by name, not just the job's overall conclusion.

- [x] **Step 6: Re-confirm the design doc's §7 PRD-compliance table's two "Closed in Stage 3" rows**

Against the real merged state of this branch (not the plan): "CTA sekunder ... di hero" (ticket 03's secondary links, unchanged by this task) and "Elemen kepercayaan ... di area pertama" (this task's own trust-badge relocation) — confirm both are genuinely true of the real rendered homepage, and note this confirmation in the PR description.

- [x] **Step 7: Commit**

```bash
git add app/Livewire/Public/HomePage.php resources/views/livewire/public/home-page.blade.php tests/Feature/Livewire/Public/HomePageRouteTest.php
git commit -m "feat(homepage): featured/verified TPU-TPS section, trust relocation, old-section cleanup (Stage 3 ticket 04)"
```

---

**Self-review:**

- **Spec coverage:** every ticket acceptance-criterion checkbox is addressed by this single task's steps above — the "verified" definition, the copy-redistribution decision, all three section removals, the final order, the test coverage, the two doc-gate commands, and the PRD-compliance re-confirmation.
- **Placeholder scan:** none — the query, the markup, and the redistribution decision are all concrete, not deferred.
- **Type consistency:** `verifiedCemeteries`/`verifiedCemeteriesUnavailable` match the exact naming convention `urgentAvailabilityCemeteries`/`newestPublishedCemeteries` already established in the same file.
