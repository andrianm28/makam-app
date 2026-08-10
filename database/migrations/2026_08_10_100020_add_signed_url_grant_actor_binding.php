<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 * Additive and forward-only, matching the already-landed vault migrations:
 * the create migration is never edited in place, and `down()` is deliberately
 * non-destructive because grant history is durable access evidence.
 *
 * The column is nullable only because an additive `NOT NULL` column cannot be
 * added to a table that may already hold rows without inventing a backfill
 * value for grants whose issuing actor was never captured. Nullability is NOT
 * a permission: `Actions\IssueSignedUrl` can only reach the write after
 * `DocumentAccessPolicy` has established an authenticated actor, so every
 * grant it mints has a non-null `actor_ref`, and any row with a NULL
 * `actor_ref` predates this binding and MUST be treated as non-redeemable
 * (fail closed) by the Task 7 redemption path.
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
    }

    public function down(): void
    {
        // Intentionally non-destructive: dropping the binding would silently
        // widen every outstanding grant back to a pure bearer credential.
    }
};
