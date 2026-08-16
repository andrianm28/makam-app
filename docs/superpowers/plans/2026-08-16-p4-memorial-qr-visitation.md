# P4 — Memorial + QR + Visitation Booking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Visitors can book cemetery visitations (mode-aware, capacity-safe, duplicate-proof) and resolve memorial QR tokens to privacy-controlled projections, with full public + admin + family surfaces.

**Architecture:** Two file-disjoint lane-groups. Visitation: policy/blackout/booking/date-capacity models + `RequestVisitation` (atomic capacity via the date-ledger row lock, idempotent), public `/kunjungan` page (mode from `PublicCapabilityProjection`), operator bookings resource with per-cemetery scoping. Memorial: profile/editor/content/media/QR/moderation domain (privacy-first per kiro AC1–AC8), `memorialMode()` on ModeResolver (G-MEM-01), `/m/{token}` resolve (gate-checked fail-closed), family surface, admin moderation + QR (endroid/qr-code, SVG). Spec: `docs/superpowers/specs/2026-08-16-memorial-qr-visitation-design.md`.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / Livewire 4 / PostgreSQL 18 + SQLite (tests) / endroid/qr-code (SVG writer — CI has no gd/imagick).

## Global Constraints

- G-MEM-01 closed → `/m/{token}` renders the uniform "memorial tidak tersedia" response for unknown/revoked/closed (no enumeration); family/admin surfaces fully functional; activation via `GateActivationRecorder` (existing flow).
- Visitation mode authority = `PublicCapabilityProjection::forCemetery($cemetery)->visitationMode` (server-side; never a front-end flag); `CemeteryVisitationPolicy` manages bookable-mode details only.
- `RequestVisitation` transaction order: `lockForUpdate` the date-capacity row (upsert) → blackout check (reason surfaced) → operating-hours check → `booked_count + visitor_count <= daily_capacity` (else `VisitationCapacityExceededException`) → insert booking + increment count → audit → outbox, one transaction (AC4). Idempotent on `idempotency_key` (unique; duplicate → incumbent, narrow classifier — SubmitBookingDraft pattern).
- Event names come from the catalog FIRST: `visit.booking_confirmed.v1` and `memorial.unpublished.v1` ALREADY exist (append rows only for NEW events: `visit.booking_requested.v1`, `memorial.profile_created.v1`, `memorial.published.v1`, `memorial.qr_token_rotated.v1`, `memorial.content_moderated.v1` — same table format, no renames).
- Memorial: `memorial_profiles.grave_record_id` is the ONLY GraveRegistry link (AC7 — nothing copied); privacy default `private`; editor grants require `consent_evidence_ref` (document-vault ref, AC1); QR tokens are random opaque (`Str::random(48)`-class entropy, never derived — AC4); one active token per profile (partial unique on `(profile_id) WHERE revoked_at IS NULL` — mutable rows, releases on revoke); unpublish immediate (AC5).
- The public projection renders ONLY the explicit allowlist (approved content/media + allowlisted profile fields); private fields never render (AC3).
- Media uploads via `UploadDocument::upload` (quarantine → scan → `promote()`); memorial media usable only in `DocumentState::Accepted`.
- Admin cemetery scoping is NEW (no existing pattern): the visitation operator resource + memorial moderation use `ScopeAssignmentReader::grantedEntityIds($actorIdentifier, 'cemetery')`; grants via the existing `GrantScopeAssignment`; operators see only their assigned cemeteries (kiro visitation AC6).
- `endroid/qr-code` pinned `^` in composer.json + lockfile committed; SVG writer only (no gd/imagick — CI extension list); the QR encodes ONLY the token URL.
- Per-cemetery public surfaces require the cemetery `published()` (the CemeteryDetail 404 discipline: re-check on mount AND render).
- Audit actions (new constants): `CEMETERY_VISITATION_POLICY_UPDATED`, `VISITATION_REQUESTED`, `VISITATION_STATUS_CHANGED`, `MEMORIAL_PROFILE_CREATED`, `MEMORIAL_EDITOR_GRANTED`, `MEMORIAL_PUBLISHED`, `MEMORIAL_UNPUBLISHED`, `MEMORIAL_QR_ROTATED`, `MEMORIAL_CONTENT_MODERATED` — none on `SensitiveActions::ACTIONS` except where a policy decision says otherwise.
- Gates: `composer lint`, `composer analyse`, `php artisan test` per lane; CI (incl. PG18) gates every merge — branches must carry NO forward class references (phpstan 0 errors per PR; the P3 CI lesson). Lane dispatch is staged: domains (Tasks 1+3) merge before surfaces (Tasks 2+4).
- Worktree execution: branch per lane from `docs/design-system-and-planning`; ledger at `.superpowers/sdd/2026-08-16-memorial-qr-visitation/progress.md`.

---

## Task 1: Visitation domain (Lane 1 — Visitation)

**Files:**
- Create: migrations `create_cemetery_visitation_policies_table`, `create_visitation_blackout_dates_table`, `create_visitation_bookings_table`, `create_visitation_date_capacities_table`
- Create: `app/Domain/Visitation/Models/CemeteryVisitationPolicy.php`, `VisitationBlackoutDate.php`, `VisitationBooking.php`, `VisitationDateCapacity.php`
- Create: `app/Domain/Visitation/VisitationBookingStatus.php`, `VisitationAuditActions.php`
- Create: `app/Domain/Visitation/Exceptions/VisitationCapacityExceededException.php`, `VisitationBlackoutDateException.php`, `VisitationClosedDayException.php`
- Create: `app/Domain/Visitation/Actions/RequestVisitation.php`
- Create: `app/Domain/Visitation/VisitationPublicQuery.php`
- Modify: `docs/contracts/event-catalog.md` (append `visit.booking_requested.v1`)
- Test: `tests/Feature/Domain/Visitation/RequestVisitationTest.php`, `tests/Feature/Domain/Visitation/RequestVisitationTwoConnectionTest.php`, `tests/Feature/Domain/Visitation/VisitationPolicyTest.php`

**Interfaces:**
- Consumes: `Cemetery` (+ `cemetery_id`), `PublicCapabilityProjection::forCemetery(Cemetery)` (mode read in the SURFACE task — the domain validates policy details), `Audit::wrap`/`Outbox::record` (confirmed signatures), `CorrelationContext::current()?->value`.
- Produces:
  - `CemeteryVisitationPolicy` (table `cemetery_visitation_policies`): fillable `['cemetery_id','operating_hours','daily_capacity']`; `operating_hours` JSON (allowlisted keys: weekday keys `mon..sun` each `{open:'HH:MM', close:'HH:MM'}` or null); guards: daily_capacity ≥ 1, operating_hours keys allowlisted (reject unknown keys), HH:MM format; `isVisitingDay(CarbonImmutable $date): bool`; `openTimeFor(CarbonImmutable $date): ?CarbonImmutable`; `closeTimeFor(...)`; unique cemetery_id.
  - `VisitationBlackoutDate`: fillable `['policy_id','date','reason']`; reason required non-blank; unique (policy_id, date); `isBlackout(CarbonImmutable $date): bool`.
  - `VisitationBooking`: fillable `['cemetery_id','policy_id','visit_date','visitor_count','contact_phone','contact_email','accessibility_needs','facility_requests','status','idempotency_key','reference']`; casts visit_date→immutable_date, facility_requests→array, visitor_count→integer; `visitor_count` ≥ 1; `idempotency_key` unique; status default `requested`; `reference` generated `'VST-'.Carbon::now()->format('Y').'-'.Str::upper(Str::random(8))`; statuses `requested`/`confirmed`/`cancelled`/`no_show` (`VisitationBookingStatus` constants).
  - `VisitationDateCapacity`: fillable `['policy_id','date','booked_count']`; unique (policy_id, date).
  - `VisitationAuditActions`: `VISITATION_REQUESTED='VISITATION_REQUESTED'`.
  - Exceptions: `VisitationCapacityExceededException::forDate(string $date, int $capacity)`, `VisitationBlackoutDateException::forDate(string $date, string $reason)`, `VisitationClosedDayException::forDate(string $date)`.
  - `RequestVisitation::__invoke(Cemetery $cemetery, string $visitDate, int $visitorCount, string $contactPhone, ?string $contactEmail, ?string $accessibilityNeeds, array $facilityRequests, string $idempotencyKey, int|string $actorReference, string $actorRole = 'customer', AuditSource $auditSource = AuditSource::Api): VisitationBooking`:

```php
public function __invoke(
    Cemetery $cemetery,
    string $visitDate,
    int $visitorCount,
    string $contactPhone,
    ?string $contactEmail,
    ?string $accessibilityNeeds,
    array $facilityRequests,
    string $idempotencyKey,
    int|string $actorReference,
    string $actorRole = 'customer',
    AuditSource $auditSource = AuditSource::Api,
): VisitationBooking {
    $policy = CemeteryVisitationPolicy::query()->where('cemetery_id', $cemetery->getKey())->first();

    if (! $policy instanceof CemeteryVisitationPolicy) {
        throw new InvalidArgumentException('Cemetery has no visitation policy configured.');
    }

    $existing = VisitationBooking::query()->where('idempotency_key', $idempotencyKey)->first();

    if ($existing instanceof VisitationBooking) {
        return $existing;
    }

    return Audit::wrap(
        mutation: function () use ($cemetery, $policy, $visitDate, $visitorCount, $contactPhone, $contactEmail, $accessibilityNeeds, $facilityRequests, $idempotencyKey, $actorReference): VisitationBooking {
            $date = CarbonImmutable::parse($visitDate);

            $capacity = VisitationDateCapacity::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['policy_id' => $policy->getKey(), 'date' => $date->toDateString()],
                    ['booked_count' => 0],
                );

            if ($policy->isBlackout($date)) {
                throw VisitationBlackoutDateException::forDate($date->toDateString(), $policy->blackoutReasonFor($date));
            }

            if (! $policy->isVisitingDay($date)) {
                throw VisitationClosedDayException::forDate($date->toDateString());
            }

            if ($capacity->booked_count + $visitorCount > $policy->daily_capacity) {
                throw VisitationCapacityExceededException::forDate($date->toDateString(), $policy->daily_capacity);
            }

            $booking = VisitationBooking::query()->create([
                'cemetery_id' => $cemetery->getKey(),
                'policy_id' => $policy->getKey(),
                'visit_date' => $date->toDateString(),
                'visitor_count' => $visitorCount,
                'contact_phone' => $contactPhone,
                'contact_email' => $contactEmail,
                'accessibility_needs' => $accessibilityNeeds,
                'facility_requests' => $facilityRequests,
                'status' => VisitationBookingStatus::REQUESTED->value,
                'idempotency_key' => $idempotencyKey,
                'reference' => $this->nextReference(),
            ]);

            $capacity->increment('booked_count', $visitorCount);

            Outbox::record(
                eventName: 'visit.booking_requested.v1',
                eventVersion: 1,
                aggregateType: 'visitation_booking',
                aggregateId: (string) $booking->getKey(),
                data: ['booking_id' => (string) $booking->getKey(), 'cemetery_id' => (string) $cemetery->getKey(), 'visit_date' => $date->toDateString(), 'visitor_count' => $visitorCount],
                classification: OutboxClassification::Internal,
                idempotencyKey: "visitation_booking:{$booking->getKey()}",
            );

            return $booking;
        },
        action: VisitationAuditActions::VISITATION_REQUESTED,
        subject: fn (VisitationBooking $booking): AuditSubject => new AuditSubject('visitation_booking', (string) $booking->getKey()),
        outcome: AuditOutcome::Allowed,
        actorRef: $actorReference,
        actorRole: $actorRole,
        source: $auditSource,
        correlationId: app(CorrelationContext::class)->current()?->value,
    );
}
```

(Add the `QueryException` narrow classifier for `visitation_bookings_idempotency_key_unique` → re-read + return incumbent, the OrderAlreadyPaid pattern; verify `Audit::wrap`'s real named args and `firstOrCreate` under lock behavior on both engines — PG: the lockForUpdate on a missing row doesn't lock the gap; the unique (policy_id, date) backstops concurrent firstOrCreate with the classifier on `visitation_date_capacities_policy_id_date_unique`.)

  - `VisitationPublicQuery::policyFor(Cemetery $cemetery): ?CemeteryVisitationPolicy` + `bookableDates(Cemetery $cemetery, CarbonImmutable $from, CarbonImmutable $to): array` (visiting days minus blackouts, each with capacity-left from the ledger) — the public page's slot source.

- [ ] **Step 1: Write the failing tests** — `RequestVisitationTest`: happy path (booking row + capacity increment + reference + audit + outbox `visit.booking_requested.v1`); capacity exceeded; blackout refusal with reason; closed day refusal; duplicate idempotency (same key → incumbent, ONE row); no-policy refusal. `RequestVisitationTwoConnectionTest` (PG-guarded): two sessions, same date, capacity 1 — first ok, second `VisitationCapacityExceededException`, booked_count stays 1. `VisitationPolicyTest`: operating-hours validation (bad key rejected, bad HH:MM rejected), capacity ≥ 1, isVisitingDay/blackout semantics.
- [ ] **Step 2: Run to verify they fail** → FAIL (classes not found).
- [ ] **Step 3: Implement** per the Produces block.
- [ ] **Step 4: Run the tests** → PASS (two-connection skips on SQLite). Event catalog append.
- [ ] **Step 5: Gates + commit** — `feat(visitation): policy, capacity-ledger, atomic request action (P4 lane 1)`.

---

## Task 2: Visitation surfaces (Lane 2 — Visitation)

**Files:**
- Create: `app/Filament/Admin/Resources/CemeteryVisitationPolicies/CemeteryVisitationPolicyResource.php` + Pages + Schemas + Tables + `RelationManagers/BlackoutDatesRelationManager.php`
- Create: `app/Filament/Admin/Resources/VisitationBookings/VisitationBookingsResource.php` + Pages + Schemas + Tables
- Create: `app/Livewire/Public/Visitation/VisitationPage.php` + `resources/views/livewire/public/visitation/page.blade.php`
- Create: `app/Domain/Visitation/Actions/ChangeVisitationBookingStatus.php`
- Modify: `routes/web.php` (the `/kunjungan` route + comment block), `app/Providers/Filament/AdminPanelProvider.php` (nav placement is auto — resources auto-discover)
- Test: `tests/Feature/Filament/VisitationAdminTest.php`, `tests/Feature/Livewire/Public/Visitation/VisitationPageTest.php`

**Interfaces:**
- Consumes: Task 1's models/actions/query; `PublicCapabilityProjection::forCemetery`; `ScopeAssignmentReader::grantedEntityIds($actor->identityReference, ScopeEntityType::CEMETERY)`; `MasterDataAdminAuthorizerContract`; `CemeteryPublicQuery::findPublishedBySlug`.
- Produces: policy resource (single-record per cemetery via a cemetery select + the blackout RM — CreateAction/EditAction with `Audit::wrap` + `CEMETERY_VISITATION_POLICY_UPDATED`; blackout create/delete with required reason); bookings resource (table: reference, cemetery, visit_date, visitor_count, contact, status badge; **per-cemetery scoping**: `getEloquentQuery()` → when the actor holds cemetery grants, `whereIn('cemetery_id', grantedEntityIds(...))`; admins see all; status actions confirm/cancel/no-show via `ChangeVisitationBookingStatus` — audit `VISITATION_STATUS_CHANGED` + outbox `visit.booking_confirmed.v1` on confirm); the public page:

`VisitationPage` (Livewire component): `mount(?string $cemeterySlug)` — null → cemetery picker state; else resolve `CemeteryPublicQuery::findPublishedBySlug` (abort(404) discipline, re-check in render); `$mode = PublicCapabilityProjection::forCemetery($cemetery)->visitationMode`; INFORMATION_ONLY → info banner (visiting hours from the policy when present, else the capability default) + NO form; BOOKABLE → form (date select from `VisitationPublicQuery::bookableDates` — blackouts disabled with reason tooltips, closed days absent), visitor_count (inputmode numeric), contact_phone (autocomplete tel), contact_email, accessibility_needs (textarea), facility_requests (checkboxes — allowlisted), submit → `RequestVisitation` with an idempotency key (session-scoped per cemetery+date+contact hash — the wizard's onlinePaymentSessionKey pattern) → confirmation card (reference, instructions, change/cancel note, fallback contact → `route('bantuan.index')`); duplicate submit → incumbent confirmation. Mode-aware copy per the kiro design (unmistakable info-only vs bookable).
- Routes: `Route::get('/kunjungan', VisitationPage::class)->name('kunjungan.index');` + `Route::get('/kunjungan/{cemeterySlug}', VisitationPage::class)->name('kunjungan.cemetery');` with a comment block citing the spec.

- [ ] **Step 1: Write the failing tests** — admin: access matrix (4 roles + a cemetery-granted operator sees only their cemetery's bookings — grant via `GrantScopeAssignment` with entity_type 'cemetery'), policy create + blackout with/without reason, status transitions + audit + outbox on confirm. Public: mode-aware render (INFORMATION_ONLY banner + no form; BOOKABLE form), blackout disabled with reason, submit → confirmation reference + fallback link, duplicate submit → incumbent, unpublished cemetery 404.
- [ ] **Step 2: Run to verify they fail** → FAIL.
- [ ] **Step 3: Implement** per the Produces block (the Filament patterns are established; the Livewire page mirrors CemeteryDetail's capability try/catch degraded fallback + the BookingWizard confirmation card).
- [ ] **Step 4: Run tests + gates + commit** — `feat(visitation): public booking page and operator bookings resource (P4 lane 2)`.

---

## Task 3: Memorial domain (Lane 3 — Memorial)

**Files:**
- Create: migrations `create_memorial_profiles_table`, `create_memorial_editors_table`, `create_memorial_contents_table`, `create_memorial_media_table`, `create_memorial_qr_tokens_table`, `create_moderation_cases_table`, `create_abuse_reports_table`
- Create: `app/Domain/Memorial/Models/MemorialProfile.php`, `MemorialEditor.php`, `MemorialContent.php`, `MemorialMedia.php`, `MemorialQrToken.php`, `ModerationCase.php`, `AbuseReport.php`
- Create: `app/Domain/Memorial/MemorialPrivacyMode.php`, `MemorialModerationState.php`, `MemorialAuditActions.php`
- Create: `app/Domain/Memorial/Exceptions/MemorialNotVisibleException.php`, `MemorialConsentMissingException.php`
- Create: `app/Domain/Memorial/Actions/CreateMemorialProfile.php`, `GrantMemorialEditor.php`, `PublishMemorial.php`, `UnpublishMemorial.php`, `RotateMemorialQrToken.php`, `SubmitMemorialContent.php`, `ModerateMemorialContent.php`, `ReportMemorialContent.php`, `ResolveMemorialQr.php`
- Create: `app/Domain/Memorial/MemorialPublicProjection.php` (the allowlist projection)
- Create: `app/Platform/FeatureGate/Modes/MemorialMode.php` (enum: `PublicMemorial='public_memorial'` / `Unavailable='unavailable'`, `fromGateOpen(bool)`, `fallback(): ?GateFallback`)
- Modify: `app/Platform/FeatureGate/ModeResolver.php` (+`memorialMode()`), `app/Platform/FeatureGate/Modes/ModeResolverTest.php` (gate-id list), `docs/contracts/event-catalog.md` (append `memorial.profile_created.v1`, `memorial.published.v1`, `memorial.qr_token_rotated.v1`, `memorial.content_moderated.v1`)
- Test: `tests/Feature/Domain/Memorial/MemorialProfileTest.php`, `tests/Feature/Domain/Memorial/MemorialQrTest.php`, `tests/Feature/Domain/Memorial/MemorialModerationTest.php`, `tests/Unit/Platform/FeatureGate/MemorialModeTest.php`

**Interfaces:**
- Consumes: `GraveRecord` (the ONLY link), `UploadDocument::upload` (media + consent evidence), `FeatureGateResolver::isOpen('G-MEM-01')` via `ModeResolver::memorialMode()`, `Audit::wrap`/`Outbox::record`, `DocumentState::Accepted` (media usable only when accepted).
- Produces:
  - `MemorialProfile`: fillable `['grave_record_id','privacy_mode','published_at','unpublished_at']`; privacy default `private` (MemorialPrivacyMode: PRIVATE/FAMILY_ONLY/UNLISTED/PUBLIC); guards: privacy known; delete blocked while content/editors/tokens exist; relations editors/contents/media/qrTokens/moderationCases.
  - `MemorialEditor`: fillable `['memorial_profile_id','actor_id','consent_evidence_ref','granted_at','revoked_at']`; grant REQUIRES consent_evidence_ref (else `MemorialConsentMissingException`); unique active (profile_id, actor_id) WHERE revoked_at IS NULL (partial unique — mutable rows).
  - `MemorialContent`/`MemorialMedia`: fillable `['memorial_profile_id','body'/'storage_ref','moderation_state']`; moderation_state default `pending`; media validates the document exists + Accepted.
  - `MemorialQrToken`: fillable `['memorial_profile_id','token','revoked_at','rotated_at']`; token generated `Str::random(48)` (never derived); partial unique `(memorial_profile_id) WHERE revoked_at IS NULL`; `activeFor(MemorialProfile): ?MemorialQrToken`.
  - `ModerationCase`/`AbuseReport`: per the kiro design (status open/resolved/dismissed; report requires reason).
  - `CreateMemorialProfile::__invoke(GraveRecord $grave, int|string $actorReference, string $actorRole, ?string $privacyMode = null, AuditSource $auditSource = AuditSource::Panel): MemorialProfile` — private default, audit `MEMORIAL_PROFILE_CREATED`, outbox `memorial.profile_created.v1`; idempotent per grave (one profile per grave — unique grave_record_id; duplicate → incumbent).
  - `GrantMemorialEditor`, `PublishMemorial` (private→published requires ≥1 approved content? NO — publish is independent; publishes at published_at=now), `UnpublishMemorial` (immediate; audit `MEMORIAL_UNPUBLISHED` + outbox `memorial.unpublished.v1` — the PRE-CATALOGUED event), `RotateMemorialQrToken` (revoke current + issue new; audit `MEMORIAL_QR_ROTATED` + outbox `memorial.qr_token_rotated.v1`), `SubmitMemorialContent` (pending), `ModerateMemorialContent` (pending→approved/rejected/hidden; audit `MEMORIAL_CONTENT_MODERATED` + outbox `memorial.content_moderated.v1`), `ReportMemorialContent` (creates the case).
  - `ResolveMemorialQr::__invoke(string $token, ?ActorContext $actor): MemorialPublicProjection` — the gate-checked resolve:

```php
public function __invoke(string $token, ?ActorContext $actor): MemorialPublicProjection
{
    if (app(ModeResolver::class)->memorialMode() !== MemorialMode::PublicMemorial) {
        throw MemorialNotVisibleException::becauseGateClosed();
    }

    $qr = MemorialQrToken::query()
        ->where('token', $token)
        ->whereNull('revoked_at')
        ->with('profile.contents', 'profile.media', 'profile.editors')
        ->first();

    if (! $qr instanceof MemorialQrToken) {
        throw MemorialNotVisibleException::becauseUnknownToken();
    }

    $profile = $qr->profile;

    // Privacy modes: public → anyone; unlisted → token holders (all resolvers
    // hold the token); family_only → token + an active family editor for the
    // actor; private → active editor only (token insufficient).
    if (! $profile->isVisibleTo($actor, hasToken: true)) {
        throw MemorialNotVisibleException::becausePrivacy($profile->privacy_mode);
    }

    return MemorialPublicProjection::forProfile($profile, $actor);
}
```

`isVisibleTo(ActorContext|null $actor, bool $hasToken): bool` on the profile: PUBLIC → true; UNLISTED → hasToken; FAMILY_ONLY → hasToken AND (guest? false : an active MemorialEditor for $actor->identityReference); PRIVATE → an active editor for the actor. `MemorialPublicProjection` builds the ALLOWLIST view (approved content bodies, accepted media refs, allowlisted profile fields) — never private fields.
- `MemorialMode` enum + `ModeResolver::memorialMode()` + the ModeResolverTest gate-id list update.

- [ ] **Step 1: Write the failing tests** — profile: private default, one-per-grave idempotent, delete blocked; editor: consent required (grant without → `MemorialConsentMissingException`), revoke; publish/unpublish (unpublish immediate — published_at null + outbox `memorial.unpublished.v1`); QR: token random-not-derived (generate two → differ; assert token ≠ any profile/grave id), rotate revokes old + new active, one-active-token partial unique; content: submit → pending, moderate → approved/rejected/hidden + report → case; resolve: gate closed → `MemorialNotVisibleException` (uniform — same exception class for closed/unknown/privacy); privacy matrix (public/unlisted/family_only/private × actor/guest/editor); projection allowlist (private fields absent, only approved content + accepted media).
- [ ] **Step 2: Run to verify they fail** → FAIL.
- [ ] **Step 3: Implement** per the Produces block.
- [ ] **Step 4: Run tests + gates + commit** — `feat(memorial): privacy-first profile, QR token, moderation domain (P4 lane 3)`.

---

## Task 4: Memorial surfaces (Lane 4 — Memorial)

**Files:**
- Create: `app/Livewire/Public/Memorial/MemorialPublicPage.php` + blade
- Create: `app/Livewire/Public/Memorial/MemorialFamilyPage.php` + blade (family content management — private, consent-gated)
- Create: `app/Filament/Admin/Resources/MemorialProfiles/MemorialProfileResource.php` + Pages + Schemas + Tables + RelationManagers (`EditorsRelationManager`, `ContentsRelationManager`, `MediaRelationManager`, `QrTokensRelationManager`)
- Create: `app/Filament/Admin/Resources/ModerationCases/ModerationCaseResource.php` + Pages + Schemas + Tables
- Create: `app/Domain/Memorial/MemorialQrImage.php` (endroid/qr-code SVG wrapper)
- Modify: `routes/web.php` (`/m/{token}` + `/memorial/{graveId}` family route + comment blocks), `composer.json` + `composer.lock` (endroid/qr-code `^` pinned)
- Test: `tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php`, `tests/Feature/Filament/MemorialAdminTest.php`, `tests/Unit/Domain/Memorial/MemorialQrImageTest.php`

**Interfaces:**
- Consumes: Task 3's domain; `ResolveMemorialQr`; `MemorialQrImage`; `GrantScopeAssignment` (cemetery-scoped moderation visibility — the moderation resource scopes by the reported profile's grave → cemetery grants, the visitation pattern); `UploadDocument` for family media + consent evidence.
- Produces:
  - `MemorialPublicPage` (Livewire, `/m/{token}`): mount → `ResolveMemorialQr` in try/catch → `MemorialNotVisibleException` → the uniform "memorial tidak tersedia" state (no existence leak); success → the allowlist projection render (deceased display name from allowlisted fields, approved content, accepted media, QR-holder privacy). Re-check the gate on render.
  - `MemorialFamilyPage` (Livewire, `/memorial/{profileId}`): consent-gated (active editor for the actor — else the not-visible state); content submit (pending), media upload (via `UploadDocument`, quarantine → scan), token display + QR image (SVG), rotate; change privacy (family_only/unlisted/public) audited.
  - Admin `MemorialProfileResource`: list (grave ref, privacy badge, published state), view with the relation managers (editors — grant with consent evidence field; contents/media — moderate actions per-state; QR tokens — issue/rotate + QR image display), unpublish/publish actions; gate `MasterDataAdminAuthorizerContract` + auditRoleFor; cemetery scoping via grants where the actor holds them (the visitation pattern).
  - `ModerationCaseResource`: queue (open cases first), resolve/dismiss with reason + audit.
  - `MemorialQrImage::svg(string $tokenUrl): string` — endroid/qr-code `QrCode` + `SvgWriter`, `outputString()`; unit test asserts an SVG document containing the URL payload (decode the SVG's rendered text or assert the writer output embeds the payload — the writer embeds it in a title/aria attribute; verify against the real writer and assert accordingly).
  - Composer: `composer require endroid/qr-code:^6.0` (verify the latest stable `^6` or `^5` per the resolver's recommendation at install time — pin whatever resolves, commit the lockfile; `composer audit` clean).
- Routes: `Route::get('/m/{token}', MemorialPublicPage::class)->name('memorial.show');` + `Route::get('/memorial/{profileId}', MemorialFamilyPage::class)->name('memorial.family');` with comment blocks citing the spec.

- [ ] **Step 1: Write the failing tests** — public page: gate-closed uniform state (closed + unknown token + revoked token + private-without-editor → the SAME rendered state), public profile renders the allowlist, family_only with editor renders; family page: non-editor → not-visible, editor → submit content (pending) + upload media (quarantined doc), rotate token; admin: access matrix + moderation flow + QR issuance with the SVG image; cemetery-scoped moderation visibility.
- [ ] **Step 2: Run to verify they fail** → FAIL.
- [ ] **Step 3: Implement** per the Produces block.
- [ ] **Step 4: Run tests + gates + commit** — `feat(memorial): public resolve, family surface, admin moderation + QR (P4 lane 4)`.

---

## Task 5: Docs + deploy + browser UAT + whole-branch review (post-merge)

**Files:**
- Modify: `docs/product/screen-inventory.md` (visitation policy/bookings resources, memorial profiles/moderation resources, the public pages), `docs/domain/traceability-matrix.md` (P4 rows Covered with the real test files), `docs/operations/feature-flag-registry.md` (verify G-MEM-01/G-VISIT-01 entries match the shipped code)
- Test: Playwright additions (dev).

- [ ] **Step 1: Update screen inventory + traceability** (in-place; commit `docs: screen inventory and traceability for P4 memorial + visitation`).
- [ ] **Step 2: Deploy to dev** (digest → compose update → migrate → health check).
- [ ] **Step 3: Browser UAT** (admin → visitation policy + blackout; public `/kunjungan` info-only banner + bookable form → confirmation; memorial admin → create profile + editor + QR; family page content + rotate; direct `/m/{token}` navigation with the gate closed → uniform not-available state; open G-MEM-01 on dev with evidence → resolve renders).
- [ ] **Step 4: Whole-branch review** (full phase diff, ledger minors triage, bounded fix wave + scoped re-review) then final merge + deploy.

---

## Self-review notes

- **Spec coverage:** §4.1 → Tasks 1–2; §4.2 → Tasks 3–4; §5/§6 → per-task; §7 → per-task tests + Task 5; §8 → Task 5.
- **Type consistency:** `RequestVisitation::__invoke` signature identical in Tasks 1/2; `ResolveMemorialQr` + `MemorialNotVisibleException` identical in Tasks 3/4; audit-action names consistent; the catalogued event names match the catalog (visit.booking_confirmed.v1 produced on confirm in Task 2; memorial.unpublished.v1 in Task 3).
- **Known drift risks to resolve at implementation time:** endroid/qr-code version pin (^5 vs ^6 — verify against the real package + composer audit); the SVG-writer payload assertion (verify the writer embeds the payload somewhere assertable); `firstOrCreate` under `lockForUpdate` on PG (gap locking — the unique (policy_id, date) backstop + classifier); `isVisibleTo` semantics vs the kiro AC wording; the `MemorialPublicProjection` allowlist field set (explicit, reviewed); the family page's consent-evidence upload flow (document vault client upload id idempotency).
