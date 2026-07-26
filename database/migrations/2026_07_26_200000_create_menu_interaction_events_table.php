<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `menu_interaction_events` — `.kiro/specs/public-home-and-navigation/
 * requirements.md` AC9: "WHEN a user views or clicks a menu THE SYSTEM
 * SHALL record an analytics impression or click event without sensitive
 * data."
 *
 * ---------------------------------------------------------------------------
 * Why this is a NEW table, not a reuse of `audit_events`
 * ---------------------------------------------------------------------------
 * `App\Platform\Audit\Audit::record()` was read in full before deciding
 * this (its own class doc block, and `AuditEvent`'s). Audit is built for
 * PRIVILEGED MUTATIONS with an actor: every row requires `actor_role`,
 * `outcome` (allowed|denied|failed), a `subject` reference to a real
 * record, and a conditional `reason` for sensitive actions. Anonymous
 * public navigation telemetry is a different concern on every one of those
 * axes — there is no actor (a homepage visitor is not authenticated), no
 * mutation outcome (viewing a menu changes nothing), and no domain
 * "subject" record the event is about (the subject IS the menu item
 * itself, not a row this event references). Forcing this event shape
 * through `Audit::record()` would mean inventing a fake actor role
 * ("guest") and a fake outcome ("allowed") for every single page view just
 * to satisfy required parameters that do not describe what actually
 * happened — that is a worse fit than a small, separate, honestly-scoped
 * table. This is a deliberate judgment call, documented here per this
 * batch's own brief; see the homepage batch's final report for the
 * short version.
 *
 * ---------------------------------------------------------------------------
 * "Without sensitive data" (AC9) — what this table deliberately does NOT
 * have a column for
 * ---------------------------------------------------------------------------
 * No user id, session id, IP address, user agent, or any other visitor
 * identifier. A row records only: which menu, which route, whether it was
 * an impression or a click, and when. Two anonymous rows are
 * indistinguishable from each other even if they came from the same
 * visitor — that is intentional, not a gap.
 *
 * ---------------------------------------------------------------------------
 * Scope actually wired up by this batch — see
 * `App\Platform\Analytics\MenuInteractionRecorder` and
 * `App\Livewire\Public\HomePage`
 * ---------------------------------------------------------------------------
 * Only the IMPRESSION side is recorded by this batch, for the homepage's
 * four service-card entry points (the clearest, cheapest, most defensible
 * reading of AC9's "views ... a menu" for a page that is entirely
 * server-rendered with no client-side event pipeline). The CLICK side is
 * NOT implemented: the header's nav links (`resources/views/components/mk/
 * header.blade.php`) are plain `<a href>` elements with no server
 * round-trip this batch could hook a record onto without either (a) a
 * client-side analytics beacon, which does not exist anywhere in this
 * codebase and was not fabricated here, or (b) inferring a "click" from
 * the destination page's `Referer` header, which is unreliable (conflates
 * menu clicks with direct navigation, bookmarks, and other referrers) and
 * was deliberately not built as a guess dressed up as a real signal. This
 * is a named, real gap — not silently dropped — see this batch's final
 * report.
 *
 * Schema below supports both `impression` and `click` via the
 * `interaction` column precisely so a later batch can wire up real click
 * tracking without a table migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_interaction_events', function (Blueprint $table) {
            $table->id();

            // One of header.blade.php's own $navItems keys
            // ('pemesanan'|'layanan'|'perpanjangan'|'faq') — kept as a
            // plain string, not an enum-backed column, since this is a
            // low-stakes count table, not a domain invariant.
            $table->string('menu_key', 50);

            // The href the menu pointed at when this event was recorded
            // (e.g. '/pemesanan-makam') — plain text, not a foreign key;
            // this table has no relationship to any routed resource.
            $table->string('route', 255);

            $table->string('interaction', 20);

            // When the event occurred. Deliberately separate from
            // Eloquent's created_at (see MenuInteractionEvent::$timestamps)
            // — same reasoning audit_events' occurred_at documents, though
            // the stakes here are much lower (this table carries no
            // immutability guarantee at all, unlike audit_events).
            $table->timestamp('occurred_at');

            $table->index('menu_key');
            $table->index('occurred_at');
        });

        // Real database-level guard that only `impression`/`click` can ever
        // be written, for any role — same pattern and same pgsql-only guard
        // as audit_events' `outcome` CHECK (see that migration's own doc
        // block for why: SQLite's ALTER TABLE cannot ADD CONSTRAINT, and
        // phpunit.xml's own default DB_CONNECTION is sqlite even though
        // ci.yml's real test job overrides it to pgsql).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE menu_interaction_events ADD CONSTRAINT menu_interaction_events_interaction_check '.
                "CHECK (interaction IN ('impression', 'click'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_interaction_events');
    }
};
