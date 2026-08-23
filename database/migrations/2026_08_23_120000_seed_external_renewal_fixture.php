<?php

declare(strict_types=1);

/**
 * ===========================================================================
 * THIS IS DUMMY / PLACEHOLDER DATA. NONE OF THE FOLLOWING IS REAL.
 * ===========================================================================
 * One fictional throwaway externally-marked renewal for the browser suite
 * (`tests/browser/e2e-renewal-external.spec.ts`) — closes the real gap
 * `tests/browser/e2e-renewal.spec.ts` names in its own comment: "No fixture
 * ever creates a `renewals` row."
 *
 * ---------------------------------------------------------------------------
 * Why a data migration and not `database/seeders/`
 * ---------------------------------------------------------------------------
 * Nothing in CI, the Dockerfile, or any deployment script runs
 * `php artisan db:seed` — every fixture dataset in this repository ships as
 * a timestamped data migration instead (same reasoning as
 * `2026_08_22_110000_seed_e2e_admin_vendor_test_users.php`).
 *
 * ---------------------------------------------------------------------------
 * Why `MarkExternalRenewal`, not a raw `Renewal::create()`
 * ---------------------------------------------------------------------------
 * `App\Domain\Renewal\Actions\MarkExternalRenewal` is this codebase's only
 * sanctioned write path for an externally-marked renewal (see that class's
 * own doc block) — a real, audited write, not a test-only bypass. Calling
 * it here keeps the seeded row indistinguishable, from the application's
 * own point of view, from a real admin-recorded external marking. It
 * requires an authenticated actor holding `ActorRole::ADMIN` and a
 * `ScopeGrantLevel::PRIVILEGED` cemetery grant, resolved via
 * `Illuminate\Support\Facades\Auth::guard('web')->user()`
 * (`ActorContextResolver`) — this migration logs a throwaway fixture admin
 * in via `Auth::guard('web')->login()` for exactly the duration of the
 * call, then logs out, so no session state leaks into the migration
 * process beyond this one write.
 *
 * ---------------------------------------------------------------------------
 * Gated behind `config('e2e_fixtures.seed_external_renewal')`, default false
 * ---------------------------------------------------------------------------
 * Same reasoning as `2026_08_22_110000_seed_e2e_admin_vendor_test_users.php`
 * and `config/e2e_fixtures.php`'s own doc block: `RefreshDatabase` applies
 * this migration once per PHPUnit process, so an unconditional `up()` would
 * permanently write a real `renewals`/`renewal_external_markings`/
 * `audit_events` row into every unrelated Feature test's database.
 *
 * ---------------------------------------------------------------------------
 * The grave record and its cemetery are looked up, never hardcoded
 * ---------------------------------------------------------------------------
 * `2026_08_08_100010_seed_example_grave_records.php` seeds grave records
 * with generated ids not guaranteed stable across a from-scratch migration
 * run in a different environment — this migration queries the real,
 * current first `grave_records` row (and its real `cemetery_id`) at
 * migration time instead of assuming an id, matching
 * `2026_08_22_110000_seed_e2e_admin_vendor_test_users.php`'s own
 * `$firstVendorId` precedent.
 */

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\MarkExternalRenewal;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalExternalMarking;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    private const string ADMIN_EMAIL = 'e2e-renewal-admin@example.test';

    private const string TARGET_DUE_PERIOD = '2027-01-01';

    private const string EVIDENCE = 'Kuitansi pembayaran tunai kantor TPU, No. E2E-RENEWAL-0001 (data contoh untuk pengujian).';

    private const string REASON = 'E2E-renewal browser-suite seed — pembayaran eksternal contoh, bukan data nyata.';

    public function up(): void
    {
        if (! config('e2e_fixtures.seed_external_renewal')) {
            return;
        }

        if (app()->isProduction()) {
            return;
        }

        $grave = GraveRecord::query()->orderBy('id')->first();

        if ($grave === null) {
            // No grave records exist in this environment yet — skip rather
            // than fail a real deployment, matching this repo's existing
            // fixture-skip convention (e.g. VendorListingExampleData::seed()).
            return;
        }

        if (Renewal::query()
            ->where('grave_record_id', $grave->id)
            ->where('target_due_period', self::TARGET_DUE_PERIOD)
            ->exists()
        ) {
            // Idempotency guard: the unique index this fixture exercises
            // (renewals_grave_period_unique) is the same guard a re-run
            // would otherwise trip.
            return;
        }

        $admin = User::query()->firstOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => 'E2E Renewal Admin (Contoh)',
                'password' => Hash::make('E2eRenewalAdminPassword!1'),
                'email_verified_at' => now(),
            ],
        );

        if (! DB::table('actor_role_assignments')->where('actor_identifier', (string) $admin->id)->where('role', ActorRole::ADMIN)->exists()) {
            app(GrantActorRole::class)(
                actorIdentifier: $admin->id,
                role: ActorRole::ADMIN,
                reason: 'E2E-renewal browser-suite seed — throwaway CI/dev login, not a real operator grant.',
                grantedBy: null,
            );
        }

        if (! DB::table('scope_assignments')
            ->where('actor_identifier', (string) $admin->id)
            ->where('entity_type', ScopeEntityType::CEMETERY)
            ->where('entity_id', (string) $grave->cemetery_id)
            ->exists()
        ) {
            app(GrantScopeAssignment::class)(
                actorIdentifier: $admin->id,
                entityType: ScopeEntityType::CEMETERY,
                entityId: $grave->cemetery_id,
                grantLevel: ScopeGrantLevel::PRIVILEGED,
                reason: 'E2E-renewal browser-suite seed — grants privileged access so MarkExternalRenewal is authorized for this fixture.',
                grantedBy: null,
            );
        }

        Auth::guard('web')->login($admin);

        try {
            app(MarkExternalRenewal::class)(
                $grave,
                self::TARGET_DUE_PERIOD,
                self::EVIDENCE,
                self::REASON,
            );
        } finally {
            Auth::guard('web')->logout();
        }
    }

    public function down(): void
    {
        // Deliberately does NOT delete the audit_events rows GrantActorRole/
        // GrantScopeAssignment/MarkExternalRenewal wrote above — an audit
        // log is intentionally not erasable by a migration rollback.
        $renewalIds = Renewal::query()
            ->where('target_due_period', self::TARGET_DUE_PERIOD)
            ->pluck('id');

        // `renewal_external_markings.renewal_id` is `restrictOnDelete()`
        // (2026_08_12_100020_create_renewal_external_markings_table.php) —
        // the marking row this fixture's own `MarkExternalRenewal` call
        // wrote must be deleted before its parent `renewals` row, or the
        // rollback fails with a foreign-key violation.
        RenewalExternalMarking::query()->whereIn('renewal_id', $renewalIds)->delete();

        Renewal::query()->whereIn('id', $renewalIds)->delete();

        $admin = User::query()->where('email', self::ADMIN_EMAIL)->first();

        if ($admin !== null) {
            DB::table('scope_assignments')->where('actor_identifier', (string) $admin->id)->delete();
            DB::table('actor_role_assignments')->where('actor_identifier', (string) $admin->id)->delete();
            $admin->delete();
        }
    }
};
