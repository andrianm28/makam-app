<?php

declare(strict_types=1);

use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fills in `fulfillment_owner`, `requires_schedule`, and
 * `requires_manual_confirmation` on all 12 rows seeded by
 * `2026_07_26_180700_seed_service_definitions_from_catalog.php`, and records
 * each service's first `price_versions` row — all with DUMMY/PLACEHOLDER
 * values, not real ones.
 *
 * ---------------------------------------------------------------------------
 * Why this exists as a SEPARATE, later migration rather than an edit to
 * `2026_07_26_180700_...`
 * ---------------------------------------------------------------------------
 * That migration has already run against every environment that matters
 * (dev included) — editing an already-applied migration file is exactly
 * what Laravel migration hygiene forbids (a file whose contents no longer
 * match what actually ran against existing databases). This is a new,
 * additive migration instead, with its own later timestamp.
 *
 * ---------------------------------------------------------------------------
 * THIS IS DUMMY DATA — explicitly authorized, not a catalogue decision
 * ---------------------------------------------------------------------------
 * `2026_07_26_180700_...`'s own doc block explains, correctly, why it left
 * these columns at their defaults: `docs/product/service-catalog.md` never
 * maps any of the 12 codes to a specific fulfillment owner, schedule
 * requirement, confirmation requirement, or price — inventing one at that
 * point would have been fabricated business data.
 *
 * That constraint has NOT changed — `service-catalog.md` still names no
 * per-code owner/flag/price mapping, and this migration does not edit that
 * document or claim to derive these values from it. What changed is
 * authorization: the user has explicitly requested clearly-plausible
 * placeholder/demo values for every one of these columns, specifically so
 * `dev.makam.co.id` (a real, public, non-production dev environment built
 * for exactly this kind of synthetic content — not a production system) has
 * something realistic to display instead of nulls/zeros/blanks in every
 * service card. Every value below is a reasoned, documented guess about
 * what a real Makam.co.id catalogue would plausibly look like, not a
 * transcription of any real operator contract, vendor rate card, or
 * approved pricing decision. NO downstream code should treat these as
 * authoritative business data — a future batch's admin editor (the
 * catalogue-authoring surface `2026_07_26_180000_...`'s own doc block
 * defers to "a later batch's Filament resource") is where a real operator
 * enters real owners/flags/prices, at which point this migration's values
 * are simply overwritten the normal way (owner/flags: a plain column
 * update; price: a new `RecordServiceDefinitionPriceVersion` call
 * superseding this one, the mechanism this migration itself exercises).
 *
 * ---------------------------------------------------------------------------
 * `fulfillment_owner` — reasoning per code
 * ---------------------------------------------------------------------------
 * - DOCUMENT_PROCESSING -> platform. Paperwork coordination (death
 *   certificate, burial permit, etc.) is an administrative function this
 *   platform's own staff plausibly run directly, not something handed to a
 *   cemetery or a marketplace vendor.
 * - GRAVE_DIGGING -> cemetery_operator. Digging a specific plot is
 *   inherently the cemetery operator's own physical grounds work — nobody
 *   else has standing authority over that plot.
 * - Every one of the 10 "Additional services" (AMBULANCE, FUNERAL_HOME,
 *   HEARSE, TENT_AND_CHAIRS, SOUND_SYSTEM, FLOWERS, GRAVESTONE,
 *   DOCUMENTATION, CATERING, LIVE_STREAMING) -> vendor. Every one of these
 *   is exactly the kind of discrete, biddable, ancillary product/service a
 *   marketplace vendor supplies — consistent with this codebase's own
 *   `App\Domain\Marketplace` module existing specifically to carry that
 *   kind of vendor-fulfilled product. None of these plausibly requires the
 *   platform's own staff or the cemetery operator's own grounds authority
 *   the way the two basic services above do.
 *
 * ---------------------------------------------------------------------------
 * `requires_schedule` — reasoning per code
 * ---------------------------------------------------------------------------
 * True — genuinely logistics/time-window-dependent, independent of the
 * burial event's own already-scheduled date/time:
 * - AMBULANCE: body transport must be dispatched for a specific pickup
 *   date/time distinct from the burial itself.
 * - HEARSE: the funeral procession vehicle is booked for a specific
 *   date/time slot.
 * - TENT_AND_CHAIRS: physical setup/teardown around the gathering needs its
 *   own before/after time window.
 * - SOUND_SYSTEM: equipment and an operator are booked for the specific
 *   ceremony date/time.
 * - CATERING: food delivery has to land within a specific date/time window
 *   to be servable.
 * - LIVE_STREAMING: the stream has to start at an exact date/time to be
 *   useful to remote mourners.
 *
 * False — no separate customer-facing date/time window beyond whatever
 * booking/case-level date the funeral itself already carries:
 * - DOCUMENT_PROCESSING: administrative processing runs on government
 *   office timelines, not a slot the customer picks.
 * - GRAVE_DIGGING: performed by the cemetery operator ahead of the burial
 *   case's own already-set date/time — it does not expose an independent
 *   window of its own.
 * - FUNERAL_HOME: a venue booking tied to the overall case duration, not a
 *   discrete scheduled slot in the same sense as a vehicle or crew booking.
 * - FLOWERS: delivered ahead of the ceremony; no distinct time-window
 *   commitment the way a live/attended service has.
 * - GRAVESTONE: custom fabrication with a production lead time, not a
 *   date/time window.
 * - DOCUMENTATION: photography/videography coverage rides on the ceremony's
 *   own timing rather than exposing a separately configured window.
 *
 * ---------------------------------------------------------------------------
 * `requires_manual_confirmation` — reasoning per code
 * ---------------------------------------------------------------------------
 * `service-catalog.md`'s own framing is "sensitive services may require
 * manual confirmation" — read here as services whose fulfillment depends on
 * a real-world process OUTSIDE this platform's ordinary vendor-acceptance
 * flow, where a wrong assumption is costly or hard to reverse:
 * - DOCUMENT_PROCESSING -> true: depends on a government/civil-registry
 *   process this platform does not control; staff should confirm the
 *   paperwork actually went through before treating it as done.
 * - GRAVE_DIGGING -> true: depends on the cemetery operator's own
 *   real-world grounds coordination (exact plot, timing around other
 *   burials); wrongly assuming it is complete is directly consequential.
 * - GRAVESTONE -> true: custom fabrication (engraving, material, design
 *   sign-off) is effectively irreversible once produced, so a manual
 *   confirmation gate before treating the order as final is warranted.
 * - Every other additional service -> false. AMBULANCE, HEARSE,
 *   TENT_AND_CHAIRS, SOUND_SYSTEM, FLOWERS, DOCUMENTATION, CATERING, and
 *   LIVE_STREAMING are all already fulfilled through the ordinary
 *   marketplace vendor-acceptance loop (a vendor accepts/rejects the
 *   booked order) — that existing mechanism is a different concept from
 *   this column's manual-confirmation gate, which is reserved for
 *   coordination with a party outside that ordinary vendor loop entirely.
 *   FUNERAL_HOME is a vendor-supplied venue booking the same way, so it
 *   follows the same reasoning as the rest of the vendor-fulfilled group.
 *
 * ---------------------------------------------------------------------------
 * Pricing: a direct `price_versions` insert, NOT the audited
 * `RecordServiceDefinitionPriceVersion` Action — corrected after a real CI
 * failure
 * ---------------------------------------------------------------------------
 * `service_definitions` has no price column by design —
 * `2026_07_26_180400_create_price_versions_table.php`'s own doc block is
 * explicit that price belongs in the separate, versioned, polymorphic
 * `price_versions` table (`Models\PriceVersion`), snapshotted per
 * `service-catalog.md`'s own rule ("Price is versioned and snapshot into
 * quote/order"). Adding a parallel price column on `service_definitions`
 * here would duplicate that table's entire job.
 *
 * This migration originally called `Actions\RecordServiceDefinitionPriceVersion`
 * directly, reasoning that doing so would exercise the exact same
 * `version_number`/`superseded_at`/audit-trail code path a real admin action
 * uses. That call also writes an `audit_events` row via `Audit::record()` —
 * which turned out to be a real regression: `RefreshDatabase` re-runs every
 * migration, including this one, before EVERY test in the suite, so those 12
 * audit rows were present as a baseline for completely unrelated tests that
 * assert an exact `AuditEvent::query()->count()` (`Tests\Feature\Audit\
 * AuditRecordTest`, `AuditWrapTransactionTest`, `Tests\Feature\FeatureGate\
 * GateActivationRecorderTest`) or `->sole()` a single audit row for one
 * action (`ServiceCatalogAuditIntegrationTest::test_record_price_version_
 * writes_an_audit_row`, which failed with `MultipleRecordsFoundException`
 * once CATERING had two `PRICE_VERSION_RECORDED` rows: this migration's and
 * the test's own). CI caught all seven failures on the first real push.
 *
 * The fix is a direct `DB::table('price_versions')->insert()` below: same
 * columns, same shape (`version_number` hardcoded to `1` — safe because this
 * table starts genuinely empty for every priceable, per the create-table
 * migration's own doc block, so every code's very first version really is
 * version 1), but zero interaction with `App\Platform\Audit\Audit::record()`.
 * `PriceVersioningTest.php` still exercises the Action's own versioning
 * mechanism directly (with its own synthetic amounts) — this migration no
 * longer needs to re-exercise that same path to be correct, it only needs to
 * get a plausible row into the table without a side effect on a shared,
 * per-test-reset concern the rest of this codebase relies on staying clean.
 *
 * Amounts are plausible Rupiah placeholders within the ranges given for
 * this task, one flat amount per service (matching every other seeded
 * price's shape — a single amount snapshotted per booked line item).
 * CATERING is deliberately recorded as a flat package price rather than a
 * per-porsi unit price: `price_versions.amount` has no unit/quantity
 * column of its own, and using a per-porsi figure here would silently read
 * as "the whole service costs ~Rp75.000" everywhere else this value is
 * displayed — a flat package amount stays honest about what the single
 * recorded number actually represents.
 *
 * ---------------------------------------------------------------------------
 * `down()`
 * ---------------------------------------------------------------------------
 * Deletes exactly the `price_versions` rows this migration inserted (by
 * priceable type/id) and resets `fulfillment_owner` /`requires_schedule` /
 * `requires_manual_confirmation` back to the prior seed defaults
 * (`null` / `false` / `false`) for the same 12 codes — symmetric with
 * `2026_07_26_180700_...`'s own `down()`, and safe to re-run `up()`
 * afterward (price versioning restarts cleanly at version 1 once its rows
 * are gone).
 *
 * Migration timestamp: `2026_07_26_220000` — after
 * `2026_07_26_200000_create_menu_interaction_events_table.php` (the latest
 * migration in this repository at the time this file was first written),
 * per this batch's explicit instruction to use a fresh timestamp slot
 * rather than squeezing into the already fully-used
 * `2026_07_26_180000`-`189999` ServiceCatalog range. Bumped from an initial
 * `2026_07_26_210000` to `220000` after discovering a same-timestamp
 * collision with a sibling batch's own concurrently-authored
 * `2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php`
 * (a different domain's migration, running in this same repository in
 * parallel) — different filenames never collide in Laravel's own
 * `migrations` tracking table, but sharing an identical timestamp prefix
 * is exactly the ambiguous ordering this codebase's own migration-hygiene
 * convention (see `2026_07_26_180000_create_service_definitions_table.php`'s
 * "checked against this table ownership at write time to avoid a
 * collision") means to prevent, so this file claims a distinct slot
 * instead of leaving two files sorted only by name at the same minute.
 */
return new class extends Migration
{
    /**
     * `code => [fulfillment_owner, requires_schedule, requires_manual_confirmation]`
     * — see this file's own doc block for the reasoning behind every value.
     *
     * @var array<string, array{0: string, 1: bool, 2: bool}>
     */
    private const array OPERATIONAL_DEFAULTS = [
        ServiceCode::DOCUMENT_PROCESSING => [FulfillmentOwner::PLATFORM, false, true],
        ServiceCode::GRAVE_DIGGING => [FulfillmentOwner::CEMETERY_OPERATOR, false, true],

        ServiceCode::AMBULANCE => [FulfillmentOwner::VENDOR, true, false],
        ServiceCode::FUNERAL_HOME => [FulfillmentOwner::VENDOR, false, false],
        ServiceCode::HEARSE => [FulfillmentOwner::VENDOR, true, false],
        ServiceCode::TENT_AND_CHAIRS => [FulfillmentOwner::VENDOR, true, false],
        ServiceCode::SOUND_SYSTEM => [FulfillmentOwner::VENDOR, true, false],
        ServiceCode::FLOWERS => [FulfillmentOwner::VENDOR, false, false],
        ServiceCode::GRAVESTONE => [FulfillmentOwner::VENDOR, false, true],
        ServiceCode::DOCUMENTATION => [FulfillmentOwner::VENDOR, false, false],
        ServiceCode::CATERING => [FulfillmentOwner::VENDOR, true, false],
        ServiceCode::LIVE_STREAMING => [FulfillmentOwner::VENDOR, true, false],
    ];

    /**
     * `code => [amount as a decimal string, source note]` — plausible
     * Indonesian Rupiah placeholders within this task's given ranges. See
     * this file's own doc block for why these are inserted directly rather
     * than through `RecordServiceDefinitionPriceVersion`, and why CATERING
     * is a flat package amount rather than a per-porsi figure.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array DUMMY_PRICES = [
        ServiceCode::DOCUMENT_PROCESSING => ['350000.00', 'Placeholder dev price — administrative processing fee, Rp200.000-Rp500.000 range.'],
        ServiceCode::GRAVE_DIGGING => ['750000.00', 'Placeholder dev price — grave-digging labour, Rp500.000-Rp1.000.000 range.'],
        ServiceCode::AMBULANCE => ['550000.00', 'Placeholder dev price — ambulance dispatch, Rp300.000-Rp800.000 range.'],
        ServiceCode::FUNERAL_HOME => ['2000000.00', 'Placeholder dev price — funeral home rental, Rp1.000.000-Rp3.000.000 range.'],
        ServiceCode::HEARSE => ['1000000.00', 'Placeholder dev price — hearse/procession vehicle, Rp500.000-Rp1.500.000 range.'],
        ServiceCode::TENT_AND_CHAIRS => ['750000.00', 'Placeholder dev price — tent and chairs rental, Rp500.000-Rp1.000.000 range.'],
        ServiceCode::SOUND_SYSTEM => ['500000.00', 'Placeholder dev price — sound system rental, Rp300.000-Rp700.000 range.'],
        ServiceCode::FLOWERS => ['250000.00', 'Placeholder dev price — flower arrangement, Rp150.000-Rp400.000 range.'],
        ServiceCode::GRAVESTONE => ['3000000.00', 'Placeholder dev price — custom gravestone fabrication, Rp2.000.000-Rp4.000.000 range.'],
        ServiceCode::DOCUMENTATION => ['1000000.00', 'Placeholder dev price — photo/video documentation, Rp500.000-Rp1.500.000 range.'],
        ServiceCode::CATERING => ['650000.00', 'Placeholder dev price — flat catering package (not per-porsi), within the Rp50.000-Rp150.000-per-porsi guidance for a modest ~10-porsi package.'],
        ServiceCode::LIVE_STREAMING => ['750000.00', 'Placeholder dev price — live streaming setup and operation, Rp500.000-Rp1.000.000 range.'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::OPERATIONAL_DEFAULTS as $code => [$owner, $requiresSchedule, $requiresManualConfirmation]) {
            DB::table('service_definitions')->where('code', $code)->update([
                'fulfillment_owner' => $owner,
                'requires_schedule' => $requiresSchedule,
                'requires_manual_confirmation' => $requiresManualConfirmation,
                'updated_at' => $now,
            ]);
        }

        foreach (self::DUMMY_PRICES as $code => [$amount, $source]) {
            $service = ServiceDefinition::findByCode($code);

            if ($service === null) {
                // Defensive only. `ServiceCodeDriftTest` guarantees all 12
                // codes exist once `2026_07_26_180700_...` has run, and this
                // migration's later timestamp guarantees it runs after that
                // one — this branch should be unreachable in practice.
                continue;
            }

            // Direct insert, not `RecordServiceDefinitionPriceVersion` — see
            // this file's own doc block for why (that Action's audit-trail
            // side effect broke unrelated tests under `RefreshDatabase`).
            // `version_number` 1 is safe here: `price_versions` starts
            // genuinely empty for every priceable, so this really is each
            // service's first version.
            DB::table('price_versions')->insert([
                'priceable_type' => ServiceDefinition::class,
                'priceable_id' => $service->id,
                'version_number' => 1,
                'amount' => $amount,
                'currency' => 'IDR',
                'source' => $source,
                'effective_from' => $now,
                'superseded_at' => null,
                'recorded_by' => 'dev-seed-migration',
            ]);
        }
    }

    public function down(): void
    {
        $codes = array_keys(self::DUMMY_PRICES);

        $serviceIds = ServiceDefinition::query()->whereIn('code', $codes)->pluck('id');

        DB::table('price_versions')
            ->where('priceable_type', ServiceDefinition::class)
            ->whereIn('priceable_id', $serviceIds)
            ->delete();

        DB::table('service_definitions')->whereIn('code', $codes)->update([
            'fulfillment_owner' => null,
            'requires_schedule' => false,
            'requires_manual_confirmation' => false,
            'updated_at' => now(),
        ]);
    }
};
