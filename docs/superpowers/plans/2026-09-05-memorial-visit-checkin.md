# Memorial Visit Check-In Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a visitor scanning a memorial's existing QR code log a self-affirmed "I visited" check-in, without adding any new privacy oracle, IP/device capture, or geolocation claim.

**Architecture:** One new table (`memorial_visit_checkins`) anchored to the existing `memorial_profiles` aggregate; one new domain action (`LogMemorialVisitCheckIn`) that calls the existing `ResolveMemorialQr` first and then rate-limits and inserts; a public-page button, a family-dashboard read-only history, and an admin read-only relation manager consume it.

**Tech Stack:** Laravel 13 / PHP 8.5, PostgreSQL, Redis-backed `RateLimiter`, Livewire, Filament v5.

**Spec:** docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md

## Global Constraints

- declare(strict_types=1) on every new PHP file.
- All new visual values must come from resources/css/tokens.css — never hardcode a color/spacing value (CLAUDE.md rule #4), checked by ci/verify-docs.sh.
- vendor/bin/pint --test and vendor/bin/phpstan analyse must stay clean.
- bash ci/verify-docs.sh must stay clean.
- Real Postgres/Redis for any test touching the database — never SQLite.
- The check-in path must reuse ResolveMemorialQr's existing gate/denial sequence — never a second oracle.
- No IP address, device fingerprint, or geolocation may be captured or stored by this feature.

Test commands below use the exact recipe in `docs/operations/local-test-recipe.md` (disposable Postgres 18 + Redis 8.2 containers, the pinned `ghcr.io/andrianm28/makam-app` image — never SQLite, never the bare host PHP). Start the containers once per session per that doc's §1–§2, then substitute your own `<pg-port>`/`<redis-port>`/`<tag-or-digest>` into every `Run:` command below. `vendor/bin/phpunit`, not `php artisan test` — the recipe's own §3 note on truncated output.

---

### Task 1: `memorial_visit_checkins` table, model, and profile relation

**Files:**
- Create: `database/migrations/2026_09_05_100000_create_memorial_visit_checkins_table.php`
- Create: `app/Domain/Memorial/Models/MemorialVisitCheckin.php`
- Modify: `app/Domain/Memorial/Models/MemorialProfile.php:136-142` (insert a new relation method immediately after `qrTokens()`)
- Test: `tests/Feature/Domain/Memorial/MemorialVisitCheckInTest.php` (new)

**Interfaces:**
- Consumes: `App\Domain\Memorial\Models\MemorialProfile` (existing), `App\Domain\Memorial\MemorialModerationState::DEFAULT` (existing), `App\Domain\Memorial\Actions\CreateMemorialProfile` (existing, test-only).
- Produces: `App\Domain\Memorial\Models\MemorialVisitCheckin` (fillable: `memorial_profile_id`, `checked_in_at`, `visitor_label`, `note`, `moderation_state`) and `MemorialProfile::visitCheckIns(): HasMany<MemorialVisitCheckin>` — consumed by Task 2 (action), Task 5 (family dashboard), Task 6 (relation manager).

- [ ] **Step 1: Write the failing test**
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Memorial;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Memorial\Actions\CreateMemorialProfile;
use App\Domain\Memorial\MemorialModerationState;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Domain\Memorial\Models\MemorialVisitCheckin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `memorial_visit_checkins` schema + model + relation —
 * `docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md` §4.1.
 */
final class MemorialVisitCheckInTest extends TestCase
{
    use RefreshDatabase;

    private function cemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
    }

    private function profile(): MemorialProfile
    {
        $grave = GraveRecord::factory()->create(['cemetery_id' => $this->cemetery()->getKey()]);

        return app(CreateMemorialProfile::class)($grave, 'user:1', 'operator');
    }

    /**
     * The hard constraint from the assignment, enforced structurally: no
     * such column can ever be populated if it does not exist.
     */
    public function test_the_table_has_no_ip_device_or_geolocation_column(): void
    {
        $columns = Schema::getColumnListing('memorial_visit_checkins');

        foreach (['ip', 'ip_address', 'device', 'device_fingerprint', 'user_agent', 'latitude', 'longitude'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "memorial_visit_checkins must never carry a [{$forbidden}] column.");
        }
    }

    public function test_a_row_can_be_created_and_reached_through_the_profile_relation(): void
    {
        $profile = $this->profile();

        $checkIn = MemorialVisitCheckin::query()->create([
            'memorial_profile_id' => $profile->getKey(),
            'checked_in_at' => now(),
            'visitor_label' => 'Anak',
            'note' => 'Terima kasih sudah dirawat.',
            'moderation_state' => MemorialModerationState::DEFAULT,
        ]);

        $this->assertTrue($profile->visitCheckIns->contains($checkIn));
        $this->assertSame(MemorialModerationState::DEFAULT, $checkIn->fresh()->moderation_state);
        $this->assertNull(MemorialVisitCheckin::query()->create([
            'memorial_profile_id' => $profile->getKey(),
            'checked_in_at' => now(),
        ])->visitor_label, 'visitor_label and note must both be optional.');
    }
}
```
- [ ] **Step 2: Run test to verify it fails**
Run: `docker run --rm --network host --user 1000:1000 -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<pg-port> -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=<redis-port> -v "$(pwd)":/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:<tag> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Domain/Memorial/MemorialVisitCheckInTest.php`
Expected: FAIL with `Class "App\Domain\Memorial\Models\MemorialVisitCheckin" not found` (and, once that's fixed locally, a missing-table error) — the model and migration do not exist yet.
- [ ] **Step 3: Write minimal implementation**
```php
// database/migrations/2026_09_05_100000_create_memorial_visit_checkins_table.php
<?php

declare(strict_types=1);

use App\Domain\Memorial\MemorialModerationState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `memorial_visit_checkins` — the self-service "Catat kunjungan" visit
 * affirmation (`docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md`
 * §4.1). No IP address, device fingerprint, or geolocation column exists
 * here, and none is ever added — a hard constraint of the feature, not a
 * default that happens to go unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memorial_visit_checkins', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('memorial_profile_id')
                ->constrained('memorial_profiles')
                ->restrictOnDelete();

            $table->timestamp('checked_in_at');
            $table->string('visitor_label', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('moderation_state', 16)->default(MemorialModerationState::DEFAULT);

            $table->timestamps();

            $table->index(['memorial_profile_id', 'checked_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memorial_visit_checkins');
    }
};
```
```php
// app/Domain/Memorial/Models/MemorialVisitCheckin.php
<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `memorial_visit_checkins` — one row per logged visit
 * affirmation (`docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md`
 * §4.1). `note` is moderator-visible only in this batch — never rendered
 * on the public page or the family dashboard (see that section).
 */
final class MemorialVisitCheckin extends Model
{
    use HasUuids;

    protected $table = 'memorial_visit_checkins';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'memorial_profile_id',
        'checked_in_at',
        'visitor_label',
        'note',
        'moderation_state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_in_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MemorialProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(MemorialProfile::class, 'memorial_profile_id');
    }
}
```
```php
// app/Domain/Memorial/Models/MemorialProfile.php — insert immediately
// after the existing qrTokens() method (currently lines 136-142)
    /**
     * @return HasMany<MemorialVisitCheckin, $this>
     */
    public function visitCheckIns(): HasMany
    {
        return $this->hasMany(MemorialVisitCheckin::class, 'memorial_profile_id');
    }
```
- [ ] **Step 4: Run test to verify it passes**
Run: same command as Step 2.
Expected: PASS (2 tests, both green).
- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_09_05_100000_create_memorial_visit_checkins_table.php \
        app/Domain/Memorial/Models/MemorialVisitCheckin.php \
        app/Domain/Memorial/Models/MemorialProfile.php \
        tests/Feature/Domain/Memorial/MemorialVisitCheckInTest.php
git commit -m "feat(memorial): add memorial_visit_checkins table and model"
```

---

### Task 2: `LogMemorialVisitCheckIn` action — reuses `ResolveMemorialQr`, audited

**Files:**
- Create: `app/Domain/Memorial/Actions/LogMemorialVisitCheckIn.php`
- Modify: `app/Domain/Memorial/MemorialAuditActions.php:48-51` (add one constant after `MEMORIAL_MODERATION_CASE_DISMISSED`)
- Test: `tests/Feature/Domain/Memorial/MemorialVisitCheckInTest.php` (extend)

**Interfaces:**
- Consumes: `App\Domain\Memorial\Actions\ResolveMemorialQr::__invoke(string $token, ?ActorContext $actor): MemorialPublicProjection` (existing, unmodified), `App\Domain\Memorial\Models\MemorialVisitCheckin` (Task 1), `App\Platform\Audit\Audit::wrap(...)` (existing).
- Produces: `LogMemorialVisitCheckIn::__invoke(string $token, ?ActorContext $actor, ?string $visitorLabel, ?string $note, int|string $actorReference, string $actorRole, ?AuditSource $auditSource = null): MemorialVisitCheckin` — consumed by Task 3 (adds rate limiting to this same class) and Task 4 (public page).

- [ ] **Step 1: Write the failing test**
```php
// tests/Feature/Domain/Memorial/MemorialVisitCheckInTest.php — add these
// imports to the existing use block:
use App\Domain\Memorial\Actions\LogMemorialVisitCheckIn;
use App\Domain\Memorial\Actions\PublishMemorial;
use App\Domain\Memorial\Exceptions\MemorialNotVisibleException;
use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\MemorialPrivacyMode;
use App\Domain\Memorial\Models\MemorialQrToken;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\Models\FeatureGate;

// and add this private helper alongside profile()/cemetery():
    private function openMemorialGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-MEM-01')->update(['state' => 'open']);
        app(FeatureGateResolver::class)->forget();
    }

// and these two test methods:
    public function test_a_successful_check_in_creates_a_row_and_one_audit_event(): void
    {
        $this->openMemorialGate();
        $profile = app(PublishMemorial::class)($this->profile(), 'user:1', 'operator');
        $profile->forceFill(['privacy_mode' => MemorialPrivacyMode::PUBLIC->value])->save();
        $token = MemorialQrToken::issueFor($profile);

        $checkIn = app(LogMemorialVisitCheckIn::class)(
            $token->token,
            null,
            'Anak',
            'Terima kasih sudah dirawat.',
            'visit_session:test-session',
            'guest',
        );

        $this->assertSame($profile->getKey(), $checkIn->memorial_profile_id);
        $this->assertSame('Anak', $checkIn->visitor_label);
        $this->assertSame('Terima kasih sudah dirawat.', $checkIn->note);
        $this->assertNotNull($checkIn->checked_in_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => MemorialAuditActions::MEMORIAL_VISIT_CHECKED_IN,
            'subject_id' => $checkIn->getKey(),
        ]);
    }

    public function test_closed_gate_and_revoked_token_deny_the_check_in_with_the_same_exception_as_the_direct_resolve(): void
    {
        $profile = app(PublishMemorial::class)($this->profile(), 'user:1', 'operator');
        $profile->forceFill(['privacy_mode' => MemorialPrivacyMode::PUBLIC->value])->save();
        $token = MemorialQrToken::issueFor($profile);

        try {
            app(LogMemorialVisitCheckIn::class)($token->token, null, null, null, 'visit_session:test', 'guest');
            $this->fail('A closed gate must deny the check-in path.');
        } catch (MemorialNotVisibleException) {
            // expected — the SAME class ResolveMemorialQr throws directly.
        }

        $this->openMemorialGate();
        $token->revoke();

        try {
            app(LogMemorialVisitCheckIn::class)($token->token, null, null, null, 'visit_session:test', 'guest');
            $this->fail('A revoked token must deny the check-in path.');
        } catch (MemorialNotVisibleException) {
            // expected — same class again, no second oracle.
        }

        $this->assertDatabaseMissing('memorial_visit_checkins', ['memorial_profile_id' => $profile->getKey()]);
        $this->assertDatabaseMissing('audit_events', ['action' => MemorialAuditActions::MEMORIAL_VISIT_CHECKED_IN]);
    }
```
- [ ] **Step 2: Run test to verify it fails**
Run: `docker run --rm --network host --user 1000:1000 -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<pg-port> -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=<redis-port> -v "$(pwd)":/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:<tag> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Domain/Memorial/MemorialVisitCheckInTest.php`
Expected: FAIL with `Class "App\Domain\Memorial\Actions\LogMemorialVisitCheckIn" not found`.
- [ ] **Step 3: Write minimal implementation**
```php
// app/Domain/Memorial/MemorialAuditActions.php — insert after
// MEMORIAL_MODERATION_CASE_DISMISSED, before the closing brace
    /**
     * Added for the visit check-in self-affirmation
     * (`docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md`)
     * — the module's first unauthenticated public write path. Not on
     * `SensitiveActions::ACTIONS`, same reasoning as every constant above.
     */
    public const string MEMORIAL_VISIT_CHECKED_IN = 'MEMORIAL_VISIT_CHECKED_IN';
```
```php
// app/Domain/Memorial/Actions/LogMemorialVisitCheckIn.php
<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Actions;

use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\MemorialModerationState;
use App\Domain\Memorial\Models\MemorialVisitCheckin;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\IdentityAccess\ActorContext;

/**
 * The self-service "Catat kunjungan" write path
 * (`docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md`
 * §4.2). Calls the EXISTING `ResolveMemorialQr` FIRST and lets
 * `MemorialNotVisibleException` propagate unmodified — every denial case
 * (gate closed, unknown/revoked token, unpublished, privacy) is refused
 * here exactly as it already is on the read path, never a second oracle.
 */
final readonly class LogMemorialVisitCheckIn
{
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
- [ ] **Step 4: Run test to verify it passes**
Run: same command as Step 2.
Expected: PASS (4 tests total in the file, all green).
- [ ] **Step 5: Commit**
```bash
git add app/Domain/Memorial/Actions/LogMemorialVisitCheckIn.php \
        app/Domain/Memorial/MemorialAuditActions.php \
        tests/Feature/Domain/Memorial/MemorialVisitCheckInTest.php
git commit -m "feat(memorial): add LogMemorialVisitCheckIn, reusing ResolveMemorialQr's gate sequence"
```

---

### Task 3: Rate limiting — 5 attempts per token per 15 minutes

**Files:**
- Create: `app/Domain/Memorial/Exceptions/MemorialVisitCheckInThrottledException.php`
- Modify: `app/Domain/Memorial/Actions/LogMemorialVisitCheckIn.php` (add the throttle check between the resolve call and the `Audit::wrap` call)
- Test: `tests/Feature/Domain/Memorial/MemorialVisitCheckInTest.php` (extend)

**Interfaces:**
- Consumes: `Illuminate\Support\Facades\RateLimiter` (framework).
- Produces: `MemorialVisitCheckInThrottledException::forToken(string $token, int $retryAfterSeconds): self` with public readonly `int $retryAfterSeconds` — consumed by Task 4 (public page catch block).

- [ ] **Step 1: Write the failing test**
```php
// tests/Feature/Domain/Memorial/MemorialVisitCheckInTest.php — add:
use App\Domain\Memorial\Exceptions\MemorialVisitCheckInThrottledException;

    public function test_the_sixth_attempt_within_the_window_is_throttled_but_a_different_token_is_unaffected(): void
    {
        $this->openMemorialGate();
        $profileA = app(PublishMemorial::class)($this->profile(), 'user:1', 'operator');
        $profileA->forceFill(['privacy_mode' => MemorialPrivacyMode::PUBLIC->value])->save();
        $tokenA = MemorialQrToken::issueFor($profileA);

        $profileB = app(PublishMemorial::class)($this->profile(), 'user:1', 'operator');
        $profileB->forceFill(['privacy_mode' => MemorialPrivacyMode::PUBLIC->value])->save();
        $tokenB = MemorialQrToken::issueFor($profileB);

        for ($i = 0; $i < LogMemorialVisitCheckIn::MAX_ATTEMPTS; $i++) {
            app(LogMemorialVisitCheckIn::class)($tokenA->token, null, null, null, 'visit_session:test', 'guest');
        }

        $thrown = null;

        try {
            app(LogMemorialVisitCheckIn::class)($tokenA->token, null, null, null, 'visit_session:test', 'guest');
        } catch (MemorialVisitCheckInThrottledException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'The attempt beyond MAX_ATTEMPTS within the window must be throttled.');
        $this->assertGreaterThan(0, $thrown->retryAfterSeconds);
        $this->assertSame(
            LogMemorialVisitCheckIn::MAX_ATTEMPTS,
            MemorialVisitCheckin::query()->where('memorial_profile_id', $profileA->getKey())->count(),
            'The throttled attempt must not have written a row.'
        );

        $fromB = app(LogMemorialVisitCheckIn::class)($tokenB->token, null, null, null, 'visit_session:test', 'guest');
        $this->assertInstanceOf(MemorialVisitCheckin::class, $fromB, 'A different token must have its own, unaffected bucket.');
    }
```
- [ ] **Step 2: Run test to verify it fails**
Run: `docker run --rm --network host --user 1000:1000 -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<pg-port> -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=<redis-port> -v "$(pwd)":/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:<tag> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Domain/Memorial/MemorialVisitCheckInTest.php`
Expected: FAIL — either `Error: Undefined constant App\Domain\Memorial\Actions\LogMemorialVisitCheckIn::MAX_ATTEMPTS` or (once that's stubbed) `$thrown` stays null because the 6th attempt currently succeeds.
- [ ] **Step 3: Write minimal implementation**
```php
// app/Domain/Memorial/Exceptions/MemorialVisitCheckInThrottledException.php
<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Exceptions;

use RuntimeException;

/**
 * Thrown ONLY after `ResolveMemorialQr` has already succeeded — a
 * throttled caller already holds a token that resolves to a real,
 * visible, published memorial, so this is safe to distinguish from
 * `MemorialNotVisibleException` without creating a second enumeration
 * oracle (`docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md`
 * §4.3).
 */
final class MemorialVisitCheckInThrottledException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct($message);
    }

    public static function forToken(string $token, int $retryAfterSeconds): self
    {
        return new self("Too many visit check-ins for this token; retry in {$retryAfterSeconds}s.", $retryAfterSeconds);
    }
}
```
```php
// app/Domain/Memorial/Actions/LogMemorialVisitCheckIn.php — add the
// import, the two constants, and the throttle check

use App\Domain\Memorial\Exceptions\MemorialVisitCheckInThrottledException;
use Illuminate\Support\Facades\RateLimiter;

final readonly class LogMemorialVisitCheckIn
{
    /**
     * Concrete, load-bearing (there is no auth to rely on for abuse
     * prevention) — §4.3: enough for a handful of family members tapping
     * during one visit, not enough for sustained/scripted repetition.
     */
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

        $key = 'memorial-visit-checkin:'.$token;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw MemorialVisitCheckInThrottledException::forToken($token, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return Audit::wrap(
            // ...unchanged from Task 2...
        );
    }
}
```
- [ ] **Step 4: Run test to verify it passes**
Run: same command as Step 2.
Expected: PASS (5 tests total in the file, all green).
- [ ] **Step 5: Commit**
```bash
git add app/Domain/Memorial/Exceptions/MemorialVisitCheckInThrottledException.php \
        app/Domain/Memorial/Actions/LogMemorialVisitCheckIn.php \
        tests/Feature/Domain/Memorial/MemorialVisitCheckInTest.php
git commit -m "feat(memorial): rate-limit visit check-ins per token (5/15min)"
```

---

### Task 4: Public page — "Catat kunjungan" button

**Files:**
- Modify: `app/Livewire/Public/Memorial/MemorialPublicPage.php`
- Modify: `resources/views/livewire/public/memorial/public-page.blade.php:100-104` (insert the new card before the closing footer paragraph)
- Test: `tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php` (extend)

**Interfaces:**
- Consumes: `App\Domain\Memorial\Actions\LogMemorialVisitCheckIn::__invoke(...)` (Task 3), `App\Domain\Memorial\Exceptions\MemorialVisitCheckInThrottledException` (Task 3).
- Produces: `MemorialPublicPage::logVisit(): void` Livewire action; public properties `$visitorLabel`, `$visitNote`, `$checkedIn`, `$checkInNotice`, `$checkInError`.

- [ ] **Step 1: Write the failing test**
```php
// tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php — add
// these two test methods in the PUBLIC PAGE section (no new imports
// needed — assertDatabaseHas/assertDatabaseMissing take the table name):
    public function test_the_check_in_button_renders_only_when_the_memorial_is_visible(): void
    {
        $this->openMemorialGate();
        $profile = $this->profile(MemorialPrivacyMode::PUBLIC->value);
        app(PublishMemorial::class)($profile, 'moderator:1', 'moderator');
        $token = $this->tokenFor($profile);

        Livewire::test(MemorialPublicPage::class, ['token' => $token->token])
            ->assertOk()
            ->assertSee('Catat kunjungan');

        // The seeded-closed-gate case from the top of this file must NOT
        // show the button on the uniform not-visible state.
        FeatureGate::query()->where('gate_id', 'G-MEM-01')->update(['state' => 'closed']);
        app(FeatureGateResolver::class)->forget();

        Livewire::test(MemorialPublicPage::class, ['token' => $token->token])
            ->assertOk()
            ->assertSee(self::UNIFORM_NOT_VISIBLE)
            ->assertDontSee('Catat kunjungan');
    }

    public function test_logging_a_visit_creates_a_row_and_shows_the_confirmation(): void
    {
        $this->openMemorialGate();
        $profile = $this->profile(MemorialPrivacyMode::PUBLIC->value);
        app(PublishMemorial::class)($profile, 'moderator:1', 'moderator');
        $token = $this->tokenFor($profile);

        Livewire::test(MemorialPublicPage::class, ['token' => $token->token])
            ->set('visitorLabel', 'Cucu')
            ->call('logVisit')
            ->assertHasNoErrors()
            ->assertSee('Kunjungan dicatat. Terima kasih.');

        $this->assertDatabaseHas('memorial_visit_checkins', [
            'memorial_profile_id' => $profile->getKey(),
            'visitor_label' => 'Cucu',
        ]);
    }

    public function test_logging_a_visit_after_the_gate_closes_mid_session_writes_no_row(): void
    {
        $this->openMemorialGate();
        $profile = $this->profile(MemorialPrivacyMode::PUBLIC->value);
        app(PublishMemorial::class)($profile, 'moderator:1', 'moderator');
        $token = $this->tokenFor($profile);

        $component = Livewire::test(MemorialPublicPage::class, ['token' => $token->token])->assertOk();

        FeatureGate::query()->where('gate_id', 'G-MEM-01')->update(['state' => 'closed']);
        app(FeatureGateResolver::class)->forget();

        $component->call('logVisit');

        $this->assertDatabaseMissing('memorial_visit_checkins', ['memorial_profile_id' => $profile->getKey()]);
    }
```
- [ ] **Step 2: Run test to verify it fails**
Run: `docker run --rm --network host --user 1000:1000 -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<pg-port> -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=<redis-port> -v "$(pwd)":/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:<tag> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php`
Expected: FAIL — `assertSee('Catat kunjungan')` fails (text absent) and `call('logVisit')` fails with a Livewire "method does not exist" error.
- [ ] **Step 3: Write minimal implementation**
```php
// app/Livewire/Public/Memorial/MemorialPublicPage.php — add imports,
// properties, and the logVisit() method

use App\Domain\Memorial\Actions\LogMemorialVisitCheckIn;
use App\Domain\Memorial\Exceptions\MemorialVisitCheckInThrottledException;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use Illuminate\Support\Facades\Validator;

final class MemorialPublicPage extends Component
{
    public string $token = '';

    public string $visitorLabel = '';

    public string $visitNote = '';

    public bool $checkedIn = false;

    public string $checkInNotice = '';

    public string $checkInError = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

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
                filled($validated['visitorLabel']) ? trim((string) $validated['visitorLabel']) : null,
                filled($validated['visitNote']) ? trim((string) $validated['visitNote']) : null,
                $actor->identityReference ?? 'visit_session:'.session()->getId(),
                $actor->isAuthenticated() ? 'authenticated_actor' : 'guest',
                AuditSource::Api,
            );

            $this->visitorLabel = '';
            $this->visitNote = '';
            $this->checkedIn = true;
            $this->checkInNotice = 'Kunjungan dicatat. Terima kasih.';
        } catch (MemorialNotVisibleException) {
            // render() re-checks and already shows the uniform not-visible
            // state on this same request — no $denialReason branch here,
            // per this class's own doc block.
        } catch (MemorialVisitCheckInThrottledException $exception) {
            $this->checkInError = "Kunjungan baru saja dicatat. Coba lagi dalam {$exception->retryAfterSeconds} detik.";
        }
    }

    // render() unchanged from the existing implementation.
}
```
```blade
{{-- resources/views/livewire/public/memorial/public-page.blade.php —
     insert before the closing "hanya dapat diakses..." footer paragraph
     (currently lines 102-104), still inside the @else (visible) branch --}}
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
- [ ] **Step 4: Run test to verify it passes**
Run: same command as Step 2.
Expected: PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Livewire/Public/Memorial/MemorialPublicPage.php \
        resources/views/livewire/public/memorial/public-page.blade.php \
        tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php
git commit -m "feat(memorial): add the Catat kunjungan button to the public memorial page"
```

---

### Task 5: Family dashboard — read-only visit history

**Files:**
- Modify: `app/Livewire/Public/Memorial/MemorialFamilyPage.php:336-370` (`viewData()`)
- Modify: `resources/views/livewire/public/memorial/family-page.blade.php` (new section after "Media (quarantine-first lifecycle)", before "QR token")
- Test: `tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php` (extend, family page section)

**Interfaces:**
- Consumes: `MemorialProfile::visitCheckIns(): HasMany<MemorialVisitCheckin>` (Task 1).
- Produces: two new `viewData()` keys, `visitCheckIns` and `visitCheckInCount`, read by the blade view only.

- [ ] **Step 1: Write the failing test**
```php
// tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php — add
// to the existing use block:
use App\Domain\Memorial\Actions\LogMemorialVisitCheckIn;

// and add in the FAMILY PAGE section:
    public function test_family_dashboard_shows_the_visit_count_and_history_to_an_active_editor(): void
    {
        $this->openMemorialGate();
        $profile = $this->profile(MemorialPrivacyMode::PUBLIC->value);
        app(PublishMemorial::class)($profile, 'moderator:1', 'moderator');
        $token = $this->tokenFor($profile);
        $editor = User::factory()->create();
        $this->editorFor($profile, $editor);

        app(LogMemorialVisitCheckIn::class)($token->token, null, 'Cucu', null, 'visit_session:test', 'guest');

        $this->actingAs($editor);

        Livewire::test(MemorialFamilyPage::class, ['profileId' => $profile->getKey()])
            ->assertOk()
            ->assertSee('Cucu');
    }

    public function test_family_dashboard_never_reveals_visit_data_to_a_non_editor(): void
    {
        $this->openMemorialGate();
        $profile = $this->profile(MemorialPrivacyMode::PUBLIC->value);
        app(PublishMemorial::class)($profile, 'moderator:1', 'moderator');
        $token = $this->tokenFor($profile);
        app(LogMemorialVisitCheckIn::class)($token->token, null, 'RahasiaKeluarga', null, 'visit_session:test', 'guest');

        $stranger = User::factory()->create();
        $this->actingAs($stranger);

        Livewire::test(MemorialFamilyPage::class, ['profileId' => $profile->getKey()])
            ->assertOk()
            ->assertSee(self::UNIFORM_NOT_VISIBLE)
            ->assertDontSee('RahasiaKeluarga');
    }
```
- [ ] **Step 2: Run test to verify it fails**
Run: `docker run --rm --network host --user 1000:1000 -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<pg-port> -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=<redis-port> -v "$(pwd)":/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:<tag> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php`
Expected: FAIL — `assertSee('Cucu')` fails, the family page does not render any check-in data yet.
- [ ] **Step 3: Write minimal implementation**
```php
// app/Livewire/Public/Memorial/MemorialFamilyPage.php — inside
// viewData()'s existing $this->visible branch, add two more entries to
// the returned array (the profile()/media()/contents() lines are
// unchanged):

        $visitCheckIns = $this->profile->visitCheckIns()->orderByDesc('checked_in_at')->limit(20)->get();
        $visitCheckInCount = $this->profile->visitCheckIns()->count();

        return [
            'contents' => $contents,
            'media' => $media,
            'pendingUploads' => $pendingUploads,
            'qrSvg' => $qrSvg,
            'activeToken' => $activeToken,
            'visitCheckIns' => $visitCheckIns,
            'visitCheckInCount' => $visitCheckInCount,
        ];

// and add matching defaults to the early-return branch above it:

        if (! $this->visible || ! $this->profile instanceof MemorialProfile) {
            return [
                'contents' => [],
                'media' => [],
                'pendingUploads' => [],
                'qrSvg' => null,
                'activeToken' => null,
                'visitCheckIns' => [],
                'visitCheckInCount' => 0,
            ];
        }
```
```blade
{{-- resources/views/livewire/public/memorial/family-page.blade.php —
     new section after "Media (quarantine-first lifecycle)", before
     "QR token (AC4/AC5)" --}}
<section class="mt-6">
    <x-mk.card>
        <h2 class="text-lg font-semibold text-neutral-900">Riwayat kunjungan</h2>
        <p class="mt-1 max-w-prose text-sm text-neutral-600">
            Kunjungan yang dicatat sendiri oleh pengunjung; bukan bukti lokasi
            terverifikasi. Total: {{ $visitCheckInCount }}.
        </p>

        @if (count($visitCheckIns) > 0)
            <ul class="mt-3 space-y-1">
                @foreach ($visitCheckIns as $checkIn)
                    <li class="text-sm text-neutral-600">
                        {{ $checkIn->checked_in_at->translatedFormat('d F Y H:i') }}
                        — {{ $checkIn->visitor_label ?? 'Tanpa nama' }}
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-3 text-sm text-neutral-600">Belum ada kunjungan yang dicatat.</p>
        @endif
    </x-mk.card>
</section>
```
- [ ] **Step 4: Run test to verify it passes**
Run: same command as Step 2.
Expected: PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Livewire/Public/Memorial/MemorialFamilyPage.php \
        resources/views/livewire/public/memorial/family-page.blade.php \
        tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php
git commit -m "feat(memorial): show read-only visit history on the family dashboard"
```

---

### Task 6: Admin — `VisitCheckInsRelationManager` (read-only)

**Files:**
- Create: `app/Filament/Admin/Resources/MemorialProfiles/RelationManagers/VisitCheckInsRelationManager.php`
- Test: `tests/Feature/Filament/MemorialAdminTest.php` (extend)

**Interfaces:**
- Consumes: `MemorialProfile::visitCheckIns()` (Task 1), `MemorialProfileResource::canAccess()` (existing).
- Produces: nothing consumed elsewhere — this is the final surface in the plan.

- [ ] **Step 1: Write the failing test**
```php
// tests/Feature/Filament/MemorialAdminTest.php — add to the existing use block:
use App\Domain\Memorial\Actions\LogMemorialVisitCheckIn;
use App\Domain\Memorial\Actions\PublishMemorial;
use App\Filament\Admin\Resources\MemorialProfiles\RelationManagers\VisitCheckInsRelationManager;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\Models\FeatureGate;

    public function test_admin_can_view_check_in_notes_read_only(): void
    {
        $cemetery = $this->cemetery();
        $profile = $this->profile($cemetery, MemorialPrivacyMode::PUBLIC->value);
        app(PublishMemorial::class)($profile, 'moderator:1', 'moderator');
        $token = MemorialQrToken::issueFor($profile);

        FeatureGate::query()->where('gate_id', 'G-MEM-01')->update(['state' => 'open']);
        app(FeatureGateResolver::class)->forget();

        app(LogMemorialVisitCheckIn::class)(
            $token->token,
            null,
            'Cucu',
            'Berkunjung setiap minggu.',
            'visit_session:test',
            'guest',
        );

        $this->admin();
        $this->forgetResolvedActorContext();

        Livewire::test(VisitCheckInsRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewMemorialProfile::class,
        ])
            ->assertOk()
            ->assertSee('Cucu')
            ->assertSee('Berkunjung setiap minggu.')
            ->assertSee('Menunggu');
    }
```
- [ ] **Step 2: Run test to verify it fails**
Run: `docker run --rm --network host --user 1000:1000 -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<pg-port> -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=<redis-port> -v "$(pwd)":/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:<tag> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Filament/MemorialAdminTest.php`
Expected: FAIL with `Class "App\Filament\Admin\Resources\MemorialProfiles\RelationManagers\VisitCheckInsRelationManager" not found`.
- [ ] **Step 3: Write minimal implementation**
```php
// app/Filament/Admin/Resources/MemorialProfiles/RelationManagers/VisitCheckInsRelationManager.php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MemorialProfiles\RelationManagers;

use App\Domain\Memorial\MemorialModerationState;
use App\Filament\Admin\Resources\MemorialProfiles\MemorialProfileResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * `memorial_visit_checkins` for `MemorialProfileResource` — moderator
 * VISIBILITY of check-in notes only
 * (`docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md`
 * §4.6). Read-only, mirroring `MediaRelationManager`'s own precedent: no
 * per-state moderation action exists for this field in this batch.
 */
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
                TextColumn::make('checked_in_at')
                    ->label('Waktu kunjungan')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('visitor_label')
                    ->label('Nama')
                    ->placeholder('—'),

                TextColumn::make('note')
                    ->label('Catatan')
                    ->wrap()
                    ->limit(160)
                    ->placeholder('—'),

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
- [ ] **Step 4: Run test to verify it passes**
Run: same command as Step 2.
Expected: PASS. No change to `MemorialProfileResource.php` is needed — Filament `^5.0` auto-discovers relation managers by directory placement (verified: none of the four existing relation managers is registered via a `getRelations()` method anywhere in that resource).
- [ ] **Step 5: Commit**
```bash
git add app/Filament/Admin/Resources/MemorialProfiles/RelationManagers/VisitCheckInsRelationManager.php \
        tests/Feature/Filament/MemorialAdminTest.php
git commit -m "feat(memorial): add read-only admin visibility for visit check-in notes"
```

---

## After all tasks: whole-branch verification

```bash
bash ci/verify-docs.sh
# Full Postgres/Redis run per docs/operations/local-test-recipe.md §3, unfiltered:
#   vendor/bin/phpunit tests/Feature/Domain/Memorial/ tests/Feature/Livewire/Public/Memorial/ tests/Feature/Filament/MemorialAdminTest.php
# Style/static analysis per that doc's §4:
#   vendor/bin/pint --test
#   vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Push and confirm the real `.github/workflows/ci.yml` run is green — per `CLAUDE.md`'s Scope note, composer/npm builds and the authoritative CI run happen there, not on this host.
