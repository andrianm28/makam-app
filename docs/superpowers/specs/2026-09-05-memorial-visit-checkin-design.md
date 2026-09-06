# Memorial Visit Check-In — Design

**Date:** 5 Sep 2026
**Status:** Draft (chosen approach already approved; this document records it, not proposes it)
**Scope:** Extend the existing Memorial/QR module (`.kiro/specs/memorial-and-qr/`, feature-gated behind `G-MEM-01`, currently seeded `closed` — `database/migrations/2026_07_26_120400_seed_feature_gate_registry.php:90`) with a self-service "Catat kunjungan" (log a visit) affirmation, so a physical QR code fixed at a grave can accumulate an ongoing visit record the way the competitor app Zahdu's core appeal does.
**Depends on:** The shipped Memorial/QR module (PR #82/#84, `docs/domain/traceability-matrix.md`'s MEM-01…MEM-06 rows) — `ResolveMemorialQr`, `MemorialQrToken`, `MemorialPublicPage`/`MemorialFamilyPage`, `MemorialProfileResource`. This batch adds one table, one action, one exception, one model, one audit constant, one relation manager, and two view/component edits. It does not modify any of the above.

## 0. Open question flagged up front — retention policy (do not implement against a guess)

**This spec deliberately does not set a retention or deletion period for `memorial_visit_checkins` rows.** `.kiro/specs/memorial-and-qr/requirements.md` AC8 already requires "approved policy" for the module's data and its own `design.md:118-120` already flags this as unresolved for the module's *existing* tables ("Retention/deletion policy under AC8 is named as a requirement but not designed here … Flagged, not guessed at"). `docs/governance/assumptions-and-gates.md:76`'s open question 11 — "What document retention applies to identity, agreements, certificates, work evidence, and memorial content?" — already names memorial content as unresolved; visit check-in rows are memorial content and inherit the same open question, not a new one. Implementing this plan means check-in rows accumulate with no expiry, matching how `memorial_contents`/`memorial_media` rows already accumulate today. **A human product/privacy decision is required before any retention/purge job is written against this table.** This is a deliberate exception to "no placeholders," carried over from the module's own precedent, not an oversight.

## 1. Finding / motivation

A competitive analysis of the Zahdu app found its core appeal is a physical QR code fixed at a specific grave, scanned repeatedly over time, standing for an ongoing family relationship to that grave (with a government grave-plot-reuse-compliance angle). makam-app already has a fully-built Memorial/QR module that mints opaque, revocable, non-enumerable QR tokens (`app/Domain/Memorial/Models/MemorialQrToken.php:74-92`) resolving to a public memorial page (`app/Livewire/Public/Memorial/MemorialPublicPage.php`) — but scanning it today does nothing except render the page; there is no visit/check-in log at all. This batch adds the missing piece: a record that a scan corresponded to a real visit, self-affirmed by the visitor, without inventing a new disclosure surface or a new privacy oracle.

## 2. Chosen approach (in scope)

1. **New table `memorial_visit_checkins`** — one row per logged visit, linked to the existing `memorial_profiles` aggregate (never to a grave record directly — see §4.1 for why).
2. **New action `LogMemorialVisitCheckIn`** that calls the EXISTING `ResolveMemorialQr` action first (§4.2) — inheriting its exact gate → token → publication → privacy sequence and its single `MemorialNotVisibleException` — then inserts the check-in row, rate-limited per token per rolling window (§4.3).
3. **A new "Catat kunjungan" button** on `MemorialPublicPage`/`resources/views/livewire/public/memorial/public-page.blade.php` (§4.4) — a deliberate, separate affirmative click, never implied by page load.
4. **A read-only visit history/count** on `MemorialFamilyPage`/`resources/views/livewire/public/memorial/family-page.blade.php` (§4.5), gated exactly as that surface already is.
5. **A new Filament relation manager** `VisitCheckInsRelationManager` for moderator visibility of check-in notes (§4.6).
6. **One new constant** on the existing `app/Domain/Memorial/MemorialAuditActions.php` (§4.7).

### 2.1 Honest framing — self-affirmation, not proof

**No geolocation capture and no cryptographic proof-of-presence exist anywhere in this design.** A check-in is an honest self-report that someone tapped a button while the QR page was open — nothing more. The public-page copy states this explicitly (§4.4's copy text: "Ini adalah catatan mandiri, bukan bukti lokasi terverifikasi" — "this is a self-report, not verified proof of location"). This is a permanent property of the feature, not a v1 limitation to close later; overclaiming verification here would misrepresent what the data means to the family who reads it and to any future compliance use.

### 2.2 Gate interaction — rides the existing gate, does not touch it

`ResolveMemorialQr` checks `app(ModeResolver::class)->memorialMode()` (`app/Platform/FeatureGate/ModeResolver.php:61`, backed by `MemorialMode::fromGateOpen()`), which resolves `G-MEM-01`'s state — no other flag matters here. `feature.memorial_public`/`feature.memorial_qr` (`2026_07_26_120400_seed_feature_gate_registry.php:131-132`) are registry-level rows no code path reads, per the same precedent `docs/domain/traceability-matrix.md:475` already documents for the visitation module's `G-VISIT-01`. Because `LogMemorialVisitCheckIn` calls `ResolveMemorialQr` as its first step, the check-in path is closed exactly when the public resolve path is closed — automatically, with no separate gate check to write or forget. **This batch does not open `G-MEM-01`.** It remains seeded `closed`; activating it for real is a separate, later product decision (out of scope, per the assignment).

## 3. Rejected alternatives

**Rejected — anchor on visitation bookings instead.** `visitation_bookings` (`.kiro/specs/visitation-booking/`) is safer on privacy (zero new grave-linked disclosure) and delivers real operational value (turns `NO_SHOW` from an operator guess into a verified signal). But it is scoped to a whole cemetery on ONE DATE, not to any specific grave/plot/memorial — it captures "I booked a visit," not "I tend this specific grave regularly," a much weaker analog to the actual competitive appeal. Not pursued.

**Rejected — a brand-new standalone grave-anchored check-in, independent of Memorial.** The most faithful 1:1 match to Zahdu (a QR resolving directly to a grave), but it forces an unresolved architectural question this codebase has never settled: whether to anchor on `grave_records.id` (registry/renewal-era, has `access_mode` privacy tiers) or `grave_plots.id` (plot-sales/reservation-era, has `plot_state`) — the two tables share no foreign key today. It also introduces a genuinely new disclosure that Memorial's own design has deliberately avoided: `MemorialPublicProjection`'s own allowlist (verified: `tests/Feature/Domain/Memorial/MemorialQrTest.php:315-332` asserts the projection never carries `graveRecordId`) exists specifically so a QR visitor never learns which grave record backs the memorial — this rejected option's entire premise is a QR that DOES resolve to a specific grave. It needs its own dedicated human privacy/legal sign-off before it should even be scoped into tasks. Not designed here.

## 4. Architecture

### 4.1 Data — `memorial_visit_checkins`

```text
memorial_visit_checkins(id, memorial_profile_id, checked_in_at, visitor_label, note, moderation_state, created_at, updated_at)
```

- `id`: uuid primary key (`HasUuids`, matching every other Memorial table).
- `memorial_profile_id`: `foreignUuid(...)->constrained('memorial_profiles')->restrictOnDelete()` — the SAME anchor every other child table in this module uses (`memorial_contents`, `memorial_media`, `memorial_qr_tokens` all restrict-on-delete against `memorial_profiles`; see `database/migrations/2026_08_16_110040_create_memorial_qr_tokens_table.php:41-43` and `2026_08_16_110020_create_memorial_contents_table.php:24-26`). This is the ONLY link the row carries — never a grave record id, never a plot id — preserving the exact boundary `MemorialProfile.php:24-30`'s own doc block states for the whole module ("AC7 — `grave_record_id` is the ONLY link to GraveRegistry … nothing writes back to it").
- `checked_in_at`: `timestamp`, not nullable, set by the action (`now()`), not a DB default — a deliberate business timestamp distinct from `created_at`, matching `memorial_profiles.published_at`'s own pattern of a business-meaning timestamp alongside the row's own `created_at`.
- `visitor_label`: `string(120)`, nullable — the visitor's own optional self-reported label (e.g. a name or relationship). Never required; no account exists to default it from.
- `note`: `string(500)`, nullable — an optional short message tied to this specific visit. **Design call, stated explicitly (not a TBD):** this note is moderator-visible only in this batch (§4.6) — it is never rendered on the public page (nothing new renders there beyond the button itself) and never rendered on the family dashboard (§4.5 shows only `checked_in_at`/`visitor_label`). An anonymous QR scanner can write this text with no authentication, so treating it the same way `memorial_contents.body` is treated before moderation (invisible to the audience most exposed to it) is the conservative default; a future batch may extend `ModerateMemorialContent`'s pattern to give this field an approve/reject/hide path and a family-visible destination once someone actually asks for that surface — not invented here.
- `moderation_state`: `string(16)`, default `MemorialModerationState::DEFAULT` (`'pending'`) — **reuses the existing enum** (`app/Domain/Memorial/MemorialModerationState.php`) rather than inventing a parallel one, per `AGENTS.md` §Documentation's "never duplicate canonical data" rule applied to code, not just docs. No action in this batch transitions this column away from `pending` (see the design call above) — it is present, and honestly inert, the same way `feature_gates`/`feature_flags` rows are seeded "present but inert until a real per-environment override exists" (`2026_07_26_120400_seed_feature_gate_registry.php:37`).
- `timestamps()`.
- Index: `['memorial_profile_id', 'checked_in_at']` — the shape both the family dashboard's ordered history query (§4.5) and the relation manager's default sort (§4.6) need.
- **No IP address, device fingerprint, or geolocation column exists on this table, and none is ever written to it** — the hard constraint from the assignment, enforced structurally by the schema having no such column at all, not by a validation rule that could be loosened later.

Migration file: `database/migrations/2026_09_05_100000_create_memorial_visit_checkins_table.php` (next available slot after `2026_09_03_150010_create_demo_data_batches_table.php`).

Model: `app/Domain/Memorial/Models/MemorialVisitCheckin.php` — `HasUuids`, `$fillable = ['memorial_profile_id', 'checked_in_at', 'visitor_label', 'note', 'moderation_state']`, `casts()` → `checked_in_at` as `immutable_datetime` (matching `MemorialQrToken::casts()`'s `revoked_at`/`rotated_at` pattern), `belongsTo(MemorialProfile::class, 'memorial_profile_id')`. `MemorialProfile.php` gains one new relation, `visitCheckIns(): HasMany`, added alongside its four existing relations (`editors()`/`contents()`/`media()`/`qrTokens()` at `MemorialProfile.php:115-142`) — no other change to that file.

### 4.2 Action — `LogMemorialVisitCheckIn`

```php
final readonly class LogMemorialVisitCheckIn
{
    public const int MAX_ATTEMPTS = 5;
    public const int DECAY_SECONDS = 900;

    public function __construct(
        private ResolveMemorialQr $resolveMemorialQr,
    ) {}

    public function __invoke(
        string $token,
        ?ActorContext $actor,
        ?string $visitorLabel,
        ?string $note,
        int|string $actorReference,
        string $actorRole,
        ?AuditSource $auditSource = null,
    ): MemorialVisitCheckin {
        $projection = ($this->resolveMemorialQr)($token, $actor);
        // ^ throws MemorialNotVisibleException uncaught for gate-closed,
        //   unknown/revoked token, unpublished, or privacy-denied — the
        //   SAME exception the public page already catches (AC5).

        $key = 'memorial-visit-checkin:'.$token;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw MemorialVisitCheckInThrottledException::forToken($token, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return Audit::wrap(
            mutation: function () use ($projection, $visitorLabel, $note): MemorialVisitCheckin {
                $checkIn = MemorialVisitCheckin::query()->create([
                    'memorial_profile_id' => $projection->profileId,
                    'checked_in_at' => now(),
                    'visitor_label' => $visitorLabel,
                    'note' => $note,
                    'moderation_state' => MemorialModerationState::DEFAULT,
                ]);

                return $checkIn->fresh() ?? $checkIn;
            },
            action: MemorialAuditActions::MEMORIAL_VISIT_CHECKED_IN,
            subject: fn (MemorialVisitCheckin $row): AuditSubject => new AuditSubject('memorial_visit_checkin', $row->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource ?? AuditSource::Api,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
```

Points that matter, cited against the real files:

- **Call sequence is non-negotiable.** `ResolveMemorialQr::__invoke()` (`app/Domain/Memorial/Actions/ResolveMemorialQr.php:46-82`) runs GATE → TOKEN → PUBLICATION → PRIVACY, in that exact order, and its own doc block (lines 14-43) explains why: a lookup that answered differently for "gate closed" vs "unknown token" would itself be an enumeration oracle. `LogMemorialVisitCheckIn` runs this UNMODIFIED, as the first statement, and lets its exception propagate — it does not re-implement any part of that sequence, and it does not catch `MemorialNotVisibleException` to translate it into something else. This satisfies the assignment's "must NOT introduce a second/different denial path or oracle" requirement structurally, not by convention.
- **The check-in never touches `MemorialProfile` or `GraveRecord` directly.** `$projection->profileId` — the SAME allowlisted field `MemorialQrTest.php:263` already asserts equals the profile id and NOT the grave record id (`MemorialQrTest.php:326`) — is the only value the mutation reads off the projection. The action holds no privacy-sensitive model at any point.
- **Constructor injection**, mirroring `App\Platform\DocumentVault\Actions\IssueSignedUrl`'s `private DocumentAccessPolicy $policy` shape (`IssueSignedUrl.php:105-106` construction), not the `app(OtherAction::class)()` call-site style some Livewire components use — this is a Domain Action depending on another Domain Action, where constructor DI is the established shape in this codebase.
- **Audited, unlike `SubmitMemorialContent`.** `SubmitMemorialContent.php:17-21`'s own doc block records that content submission is "Deliberately NOT audited and NOT an outbox event: the catalog has no `memorial.content_submitted` event, the plan's brief names no constant for it." That precedent does NOT apply here — this assignment's brief explicitly directs one new `MemorialAuditActions` constant, meaning this write IS meant to carry an audit row. The distinction is real, not arbitrary: `SubmitMemorialContent` is called only from the consent-gated family page by an already-identified editor, while `LogMemorialVisitCheckIn` is the module's first unauthenticated, public write path — worth a durable actor/time trail for later abuse investigation in a way a family member's own tribute is not.
- **No outbox event**, mirroring `ChangeMemorialPrivacy.php:31-33`'s stated precedent verbatim: "the event catalog carries no memorial privacy event, and this batch does not invent one — the audit row is the durable trail here." `docs/contracts/event-catalog.md:43-47` has no `memorial.visit_checked_in.v1` row and this batch does not add one; nothing downstream currently needs to react to a check-in.
- **`AuditSource::Api`** — the value every existing guest-triggered public Livewire write already uses (`app/Livewire/Public/Marketplace/Checkout.php:265`, `app/Livewire/Public/Visitation/VisitationPage.php:385`), not `AuditSource::Panel` (admin surfaces) or `::Console`.
- **Actor reference for an unauthenticated visitor**: mirrors `VisitationPage.php:379-382`'s exact idiom — `$actor->identityReference !== null ? (string) $actor->identityReference : 'visit_session:'.session()->getId()` for `$actorReference`, and `$actor->isAuthenticated() ? 'authenticated_actor' : 'guest'` for `$actorRole` (the `'authenticated_actor'` fallback string matches `MemorialProfileResource::auditRoleFor()`'s own vocabulary at `MemorialProfileResource.php:113`). The Livewire component (§4.4) computes these and passes them in — the action itself stays agnostic to where the reference came from, matching every other Memorial action's signature shape (`(..., int|string $actorReference, string $actorRole, ?AuditSource $auditSource = null)`).

### 4.3 Rate limiting — concrete, load-bearing, no IP

There is no authentication and, per the assignment's explicit constraint, no IP address may be captured or stored by this feature — so the rate limiter cannot be keyed by IP (even transiently in a cache key, that would be "capturing" it) or by any actor identity (none exists reliably for a guest). **The key is the resolved QR token itself**: `'memorial-visit-checkin:'.$token`. This is deliberate, not a fallback: the physical QR code is the one stable, already-opaque identifier every scanner shares, and rate-limiting "per grave's QR" is exactly the semantic the feature needs — it naturally caps how many check-ins can land against one grave in a period regardless of who is holding the phone, without needing to distinguish visitors at all.

**Concrete parameters: 5 attempts per token per 900 seconds (15 minutes)**, using Laravel's `RateLimiter` facade directly (`Illuminate\Support\Facades\RateLimiter`) — the same primitive `App\Livewire\Public\Auth\LoginPage.php:53` (`RateLimiter::tooManyAttempts($key, 5)`), `ForgotPasswordPage.php:44-56`, and `IssueSignedUrl.php`'s own doc block (lines 96-106: "Laravel's built-in `RateLimiter` facade, keyed by actor + IP, with a fixed attempt ceiling and decay window — rather than inventing a second throttle vocabulary") all reuse rather than inventing a parallel throttle mechanism. Reasoning for the concrete numbers: a single physical visit plausibly involves more than one family member tapping the button on their own phone (5 allows that), while sustained repeated hits beyond 5 within 15 minutes is not a plausible new physical visit and should be refused. `RateLimiter::hit()` runs only AFTER a successful resolve and BEFORE the insert — an attempt against a gate-closed or invalid token never consumes a rate-limit slot (there is nothing to protect there; `ResolveMemorialQr` itself carries no rate limit today, unchanged by this batch).

Exceeding the limit throws a NEW exception, `MemorialVisitCheckInThrottledException` (`app/Domain/Memorial/Exceptions/MemorialVisitCheckInThrottledException.php`), carrying `public readonly int $retryAfterSeconds` and a static factory `forToken(string $token, int $retryAfterSeconds): self`. This is intentionally a DIFFERENT exception class from `MemorialNotVisibleException`, and this is safe, not a second oracle: the throttle check runs only AFTER `ResolveMemorialQr` has already succeeded, so it can only ever be reached by a caller who already holds a token that resolves to a real, visible, published memorial. It reveals nothing an attacker without a valid token could use to distinguish "gate closed" from "unknown token" from "private" — those three remain uniformly `MemorialNotVisibleException`, exactly as today. It only ever tells an already-successful visitor "you (or someone at this grave) already logged a visit recently, try later" — not an enumeration-relevant fact.

### 4.4 Public page — the "Catat kunjungan" button

`MemorialPublicPage` (`app/Livewire/Public/Memorial/MemorialPublicPage.php`) gains three public properties (`string $visitorLabel = ''`, `string $visitNote = ''`, `bool $checkedIn = false`) and two display strings (`string $checkInNotice = ''`, `string $checkInError = ''`), plus one method:

```php
public function logVisit(): void
{
    $validated = Validator::make(
        ['visitorLabel' => $this->visitorLabel, 'visitNote' => $this->visitNote],
        ['visitorLabel' => ['nullable', 'string', 'max:120'], 'visitNote' => ['nullable', 'string', 'max:500']],
    )->validate();

    $actor = app(ActorContext::class);

    try {
        app(LogMemorialVisitCheckIn::class)(
            $this->token,
            $actor,
            filled($validated['visitorLabel']) ? trim($validated['visitorLabel']) : null,
            filled($validated['visitNote']) ? trim($validated['visitNote']) : null,
            $actor->identityReference ?? 'visit_session:'.session()->getId(),
            $actor->isAuthenticated() ? 'authenticated_actor' : 'guest',
            AuditSource::Api,
        );

        $this->visitorLabel = '';
        $this->visitNote = '';
        $this->checkedIn = true;
        $this->checkInNotice = 'Kunjungan dicatat. Terima kasih.';
    } catch (MemorialNotVisibleException) {
        // render() re-checks and will already show the uniform
        // not-visible state on this same request — nothing extra here,
        // per the class doc block's "no $denialReason branch" rule.
    } catch (MemorialVisitCheckInThrottledException $exception) {
        $this->checkInError = "Kunjungan baru saja dicatat. Coba lagi dalam {$exception->retryAfterSeconds} detik.";
    }
}
```

This deliberately does NOT call `ResolveMemorialQr` a second time to "optimize away" the redundant internal call `LogMemorialVisitCheckIn` already makes — `render()` already re-resolves on every request regardless (`MemorialPublicPage.php:61-79`'s own doc block: "Running the resolve on EVERY render is what 're-check the gate on render' means … the cheapest correct re-check"), so one extra resolve inside the action call is consistent with that page's existing philosophy, not a new inefficiency pattern.

Blade addition to `resources/views/livewire/public/memorial/public-page.blade.php`, inside the existing `@else` (visible) branch, after the media block and before the closing "hanya dapat diakses melalui kode QR" footer line (currently lines 100-104):

```blade
<x-mk.card>
    <h2 class="text-base font-semibold text-neutral-800">Catat kunjungan</h2>
    <p class="mt-1 max-w-prose text-sm text-neutral-600">
        Tandai bahwa Anda baru saja berkunjung ke sini. Ini adalah catatan
        mandiri, bukan bukti lokasi terverifikasi.
    </p>

    @if ($checkedIn)
        <x-mk.alert intent="success" class="mt-3">{{ $checkInNotice }}</x-mk.alert>
    @endif
    @if ($checkInError !== '')
        <x-mk.alert intent="danger" class="mt-3">{{ $checkInError }}</x-mk.alert>
    @endif

    <form class="mt-4 space-y-3" wire:submit="logVisit">
        <x-mk.field label="Nama Anda (opsional)" name="visitorLabel" type="text" wire:model="visitorLabel" />
        <x-mk.field label="Catatan (opsional)" name="visitNote" type="textarea" wire:model="visitNote" />
        <x-mk.button type="submit" variant="secondary">Catat kunjungan</x-mk.button>
    </form>
</x-mk.card>
```

No arbitrary Tailwind values, no hardcoded colors — every class is one this file already uses elsewhere (`text-neutral-800`, `text-neutral-600`, `max-w-prose`, `mt-1`/`mt-3`/`mt-4`, `space-y-3`), and the only interactive elements are the existing `x-mk.*` design-system components (`x-mk.card`, `x-mk.alert`, `x-mk.field`, `x-mk.button`) already in use on this same file and on `family-page.blade.php`. The copy states plainly that this is self-reported, matching §2.1, and the tone stays within the page's own documented constraint (`public-page.blade.php:19-21`: "no celebration, no engagement mechanics, no view counters") — no streak, no total-visits number, and no "share" nudge appear anywhere on this public surface. The button renders ONLY inside `@if ($visible)`, so it is structurally impossible for it to appear on the uniform not-visible state.

### 4.5 Family dashboard — read-only visit history

`MemorialFamilyPage::viewData()` (`app/Livewire/Public/Memorial/MemorialFamilyPage.php:336-370`) gains two more entries, computed the same way `$contents`/`$media` already are (only when `$this->visible` is true — the existing early-return at line 338-346 already covers the new keys with empty defaults):

```php
$visitCheckIns = $this->profile->visitCheckIns()->orderByDesc('checked_in_at')->limit(20)->get();
$visitCheckInCount = $this->profile->visitCheckIns()->count();
```

returned as `'visitCheckIns' => $visitCheckIns, 'visitCheckInCount' => $visitCheckInCount`. This requires no new gating logic: `render()` (`MemorialFamilyPage.php:135-148`) already re-checks `hasActiveEditor()` before calling `viewData()`'s branch that would populate these — a revoked editor gets the exact same empty-array fallback the existing four keys already get. `resources/views/livewire/public/memorial/family-page.blade.php` gains one new read-only section (placed after the existing "Media (quarantine-first lifecycle)" section, before "QR token"), showing the count and, for each entry, `checked_in_at` (formatted `->translatedFormat('d F Y H:i')`) and `visitor_label` (or an em-dash placeholder) — **`note` is deliberately NOT rendered here**, per the design call in §4.1: notes stay moderator-only in this batch.

### 4.6 Admin — `VisitCheckInsRelationManager`

New file `app/Filament/Admin/Resources/MemorialProfiles/RelationManagers/VisitCheckInsRelationManager.php`, placed alongside the four existing relation managers in that directory (`QrTokensRelationManager.php`, `ContentsRelationManager.php`, `MediaRelationManager.php`, `EditorsRelationManager.php`). **Read-only — no row actions**, directly mirroring `MediaRelationManager.php:22-24`'s own stated precedent verbatim: "No write actions: media moderation follows the same `ModerateMemorialContent` shape as contents when a media moderation path is approved; today the row states are visible and auditable here." The same reasoning applies to check-in notes: a per-state moderation action (approve/reject/hide) for this field is deferred, not designed here (§8).

```php
final class VisitCheckInsRelationManager extends RelationManager
{
    protected static string $relationship = 'visitCheckIns';

    protected static ?string $title = 'Kunjungan';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return MemorialProfileResource::canAccess();
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->defaultSort('checked_in_at', 'desc')
            ->columns([
                TextColumn::make('checked_in_at')->label('Waktu kunjungan')->dateTime()->sortable(),
                TextColumn::make('visitor_label')->label('Nama')->placeholder('—'),
                TextColumn::make('note')->label('Catatan')->wrap()->limit(160)->placeholder('—'),
                TextColumn::make('moderation_state')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        MemorialModerationState::APPROVED->value => 'Disetujui',
                        MemorialModerationState::REJECTED->value => 'Ditolak',
                        MemorialModerationState::HIDDEN->value => 'Disembunyikan',
                        default => 'Menunggu',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        MemorialModerationState::APPROVED->value => 'success',
                        MemorialModerationState::REJECTED->value => 'danger',
                        MemorialModerationState::HIDDEN->value => 'warning',
                        default => 'gray',
                    }),
            ]);
    }
}
```

**No `getRelations()` edit to `MemorialProfileResource.php` is needed.** Verified: `MemorialProfileResource.php` (full file read) has no `getRelations()` method at all, and none of the existing four relation managers is registered anywhere outside the `RelationManagers/` directory itself — this codebase runs on `filament/filament: ^5.0` (`composer.json:11`), whose resource relation managers auto-discover by directory placement. Dropping the new class into the same directory with a correct `$relationship` string is the entire registration step.

### 4.7 Audit constant

One addition to `app/Domain/Memorial/MemorialAuditActions.php` (currently ending at line 50 with `MEMORIAL_MODERATION_CASE_DISMISSED`):

```php
/**
 * Added for the visit check-in self-affirmation
 * (`docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md`)
 * — the module's first unauthenticated public write path. Not on
 * `SensitiveActions::ACTIONS` for the same reasoning as every other
 * constant in this file: not a financial, gate, credential, or
 * bulk-export act.
 */
public const string MEMORIAL_VISIT_CHECKED_IN = 'MEMORIAL_VISIT_CHECKED_IN';
```

No other constant is added — the relation manager in §4.6 is read-only and needs none, and the class's own existing doc-block reasoning (lines 9-19, "Deliberately NOT on `SensitiveActions::ACTIONS`") already covers why this new one needs no reason requirement either.

## 5. Data flow

Visitor scans the printed QR → `GET /m/{token}` → `MemorialPublicPage::render()` calls `ResolveMemorialQr` (unchanged) → allowlisted projection renders, including the new "Catat kunjungan" card → visitor optionally fills a label/note and clicks the button → `logVisit()` validates input → `LogMemorialVisitCheckIn` re-runs `ResolveMemorialQr` (gate/token/publish/privacy, unchanged), checks the per-token rate limit, inserts the row, writes one audit event → Livewire re-renders (re-resolving again, per the page's existing per-render philosophy) and shows the confirmation. Independently: an active family editor opens `/kenangan/{profileId}` → `MemorialFamilyPage` re-checks the consent gate → the new read-only section shows the count and recent `checked_in_at`/`visitor_label` entries for their own memorial. Independently: an admin/moderator opens the profile in `MemorialProfileResource`'s view page → the new `VisitCheckInsRelationManager` tab lists every check-in row including notes.

## 6. Error / denial states

- **Gate closed / unknown token / revoked token / unpublished / privacy-denied** → `MemorialNotVisibleException`, uniformly, exactly as `ResolveMemorialQr` already throws it today for the read path (`ResolveMemorialQr.php:39-42`'s own doc block: "Every denial … throws the SAME `MemorialNotVisibleException`"). The check-in path adds NOTHING here — it is the same call, same exception, same message vocabulary. `MemorialPublicPage::logVisit()` catches this and does nothing extra (§4.4) because `render()`'s own existing catch already produces the uniform "Memorial tidak tersedia" state on the very same request.
- **Rate limited** → `MemorialVisitCheckInThrottledException`, a distinct, new, and safe-to-distinguish state per §4.3's reasoning — shown as an inline Indonesian message with the concrete retry countdown, never a 500, never the uniform not-visible card (the memorial IS visible; only the write was throttled).
- **Validation failure** (label/note over length) → ordinary Livewire field errors via `Validator::make(...)->validate()`, matching every other form on this module's two public pages.
- **No new exception is thrown by `ResolveMemorialQr` itself** — that class is not modified by this batch at all.

## 7. Testing

Matches the module's own established no-factory-for-domain-rows, real-seeded convention (`MemorialQrTest.php`/`MemorialPublicPageTest.php`'s `cemetery()`/`grave()`/`profile()` helpers) and Filament relation-manager testing shape (`Livewire::test(RelationManagerClass::class, ['ownerRecord' => $profile, 'pageClass' => ViewMemorialProfile::class])`, per `tests/Feature/Filament/MemorialAdminTest.php:302-308`).

- **Domain (`tests/Feature/Domain/Memorial/MemorialVisitCheckInTest.php`, new file):**
  - Happy path: a public, published profile with an active token → `LogMemorialVisitCheckIn` creates a row with the correct `memorial_profile_id`, a `checked_in_at` close to `now()`, the supplied `visitor_label`/`note`, `moderation_state = 'pending'`, and writes exactly one `audit_events` row with `action = MemorialAuditActions::MEMORIAL_VISIT_CHECKED_IN`.
  - Null-optional path: both `visitorLabel` and `note` null → row inserts with both columns null (neither is required).
  - **Reuse proof (explicitly required by the assignment):** a closed gate and, separately, a revoked token, each thrown at the check-in path via `LogMemorialVisitCheckIn`, assert `MemorialNotVisibleException` — the SAME class `ResolveMemorialQr` throws directly (a parametrized/table test asserting both call sites produce `instanceof MemorialNotVisibleException`, not merely "an exception"), and assert NO `memorial_visit_checkins` row and NO audit row was written on either denial.
  - **Rate-limit proof (explicitly required by the assignment):** 5 successful check-ins against the same token succeed; the 6th within the 900-second window throws `MemorialVisitCheckInThrottledException` with a `retryAfterSeconds > 0`; a check-in against a DIFFERENT token in the same window still succeeds (bucket isolation, proving the key is per-token, not global); travelling past the decay window (`Carbon::setTestNow()` or clearing the limiter) allows a 6th check-in to succeed.
  - Schema/no-restricted-data proof: assert the `memorial_visit_checkins` table has no `ip_address`/`ip`/`device` column (a `Schema::getColumnListing()` check, mirroring design.md's own precedent for AC7's boundary — "a static/reflection check that no memorial table column duplicates a grave-record field").
- **Livewire (`tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php`, extended):**
  - The button renders when `$visible` and never renders on the uniform not-visible state (an `assertDontSee('Catat kunjungan')` case alongside the existing `assertSee(self::UNIFORM_NOT_VISIBLE)` assertions).
  - Calling `logVisit()` on a visible page creates the row and shows the notice; the assertion checks `assertDatabaseHas('memorial_visit_checkins', [...])` the same way `MemorialPublicPageTest.php:371-375`'s content-submission test already checks `memorial_contents`.
  - Calling `logVisit()` after the gate closes mid-session (mirroring `test_the_gate_is_re_checked_on_render` at `MemorialPublicPageTest.php:289-308`) writes no row.
- **Family dashboard (extended in the same test file):** an active editor sees the count and history entries for their own profile; a non-editor/stranger continues to get the existing uniform not-visible state with no check-in data ever reachable (no new assertion path needed beyond the existing `test_family_page_denies_a_non_editor_with_the_uniform_state` case, since the new section lives entirely behind the same `$visible` branch).
- **Filament (`tests/Feature/Filament/MemorialAdminTest.php`, extended):** `Livewire::test(VisitCheckInsRelationManager::class, ['ownerRecord' => $profile, 'pageClass' => ViewMemorialProfile::class])->assertOk()->assertSee(...)` against a seeded check-in row, asserting the note text and the "Menunggu" (pending) badge both render — proving moderator visibility without needing a real HTTP-triggered check-in.

## 8. Explicitly not covered

- **Retention/deletion policy** — flagged prominently in §0, not decided here (open question 11, `docs/governance/assumptions-and-gates.md:76`).
- **Geolocation or cryptographic proof-of-presence** — permanently out of scope for this feature's premise (§2.1), not a v1 gap.
- **Opening `G-MEM-01`** — this batch rides the existing gate as-is (§2.2); activation is a separate later decision.
- **Per-state moderation actions on the check-in `note`** (approve/reject/hide, mirroring `ModerateMemorialContent`) — deferred per §4.1/§4.6; the column exists and defaults to `pending` but nothing in this batch transitions it. A follow-up batch may add this once a concrete need for family-visible or public-visible notes is named.
- **A standalone grave-anchored check-in independent of Memorial** — rejected in §3, needs its own privacy/legal sign-off first.
- **An outbox event for check-ins** — no catalogued event exists and none is invented (§4.2), mirroring `ChangeMemorialPrivacy`'s own precedent.
