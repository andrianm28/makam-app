<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Binds every `signed_url_grants` row to the actor it was issued to.
 *
 * `2026_08_09_100030_create_signed_url_grants_table.php` shipped without an
 * actor column, which left a signed URL a pure BEARER credential: any
 * authenticated actor holding the token could redeem it, and the only thing
 * standing between a leaked token (browser history, a forwarded chat message,
 * a `Referer` header) and the bytes would be the redeeming actor
 * independently passing `Policies\DocumentAccessPolicy`. That re-check is
 * necessary but not sufficient — it lets ANY actor who happens to share the
 * record relationship redeem a token minted for someone else, and it makes
 * "a foreign token must 404" (the Task 7 contract) a property of who else
 * happens to be scoped to the record rather than a property of the grant.
 *
 * With `actor_ref` recorded at issuance, redemption can require BOTH that the
 * requesting actor is the actor the grant was minted for AND that the policy
 * still allows them right now (a revoked scope assignment must invalidate an
 * outstanding grant, so the binding never replaces the re-check).
 *
 * Additive and forward-only: `2026_08_09_100030` is never edited in place,
 * and `down()` is deliberately non-destructive because grant history is
 * durable access evidence.
 *
 * ---------------------------------------------------------------------------
 * Why the column is nullable AND check-constrained, not `NOT NULL`
 * ---------------------------------------------------------------------------
 * An unbound grant must be impossible, not merely discouraged. An earlier
 * revision of this migration left the column plain nullable and asserted in
 * three doc blocks that the Task 7 redemption path "MUST" treat a NULL
 * `actor_ref` as non-redeemable — a prose obligation a future implementer can
 * simply not honour, which is exactly the review finding this constraint
 * closes. `CHECK (actor_ref IS NOT NULL)` makes the database refuse the row
 * instead, so "every grant is bound to an actor" is an invariant rather than
 * an instruction.
 *
 * The column itself stays nullable at the Blueprint level and the constraint
 * is added separately under a pgsql guard, because SQLite — the local PHPUnit
 * driver — cannot add a constraint with `ALTER TABLE`. This is the same
 * convention every other closed-list and range constraint in this module
 * already uses (`documents`, `document_scans`, `document_access_events`,
 * `signed_url_grants`), so **the invariant is enforced in CI and production
 * on PostgreSQL, and is NOT enforced on the local SQLite driver.** The
 * application-side guarantee is unchanged and independent:
 * `Actions\IssueSignedUrl` can only reach the write after
 * `DocumentAccessPolicy` has established an authenticated actor, and asserts
 * a non-null reference before writing.
 *
 * `actor_ref` is a plain string for the same forward-compatibility reason
 * `scope_assignments.actor_identifier` and `document_access_events.actor_ref`
 * are: `ActorContext::$identityReference` is typed `int|string|null` because a
 * future K1/K2-backed adapter may reference identity by an external string id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signed_url_grants', function (Blueprint $table) {
            $table->string('actor_ref')->nullable()->after('document_id');

            $table->index(['actor_ref', 'expires_at'], 'signed_url_grants_actor_expires_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE signed_url_grants ADD CONSTRAINT signed_url_grants_actor_ref_not_null_check '.
                'CHECK (actor_ref IS NOT NULL)'
            );
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: dropping the binding would silently
        // widen every outstanding grant back to a pure bearer credential.
    }
};
