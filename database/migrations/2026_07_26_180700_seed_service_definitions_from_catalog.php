<?php

declare(strict_types=1);

use App\Domain\ServiceCatalog\ServiceCategory;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds `service_definitions` from `docs/product/service-catalog.md` — the
 * single canonical source this batch's brief names for the service
 * catalogue. Exactly 12 rows: the 2 "Basic services" plus the 10
 * "Additional services" that document's own two tables actually list
 * (counted directly against that file's rows — the task brief that
 * commissioned this batch said "11 additional / 13 total," but the
 * canonical file itself has 10 additional / 12 total; this migration and
 * `App\Domain\ServiceCatalog\ServiceCode` follow the real file, not the
 * brief's count — see this batch's own final report for that discrepancy),
 * using its exact codes (verbatim) and exact Indonesian labels (verbatim),
 * in each table's own listed order (basic table first, then additional).
 *
 * ---------------------------------------------------------------------------
 * Why a DATA MIGRATION, not a `database/seeders/*` class
 * ---------------------------------------------------------------------------
 * Same reasoning as `2026_07_26_170400_seed_faq_categories_and_articles.php`
 * (see that migration's own doc block): nothing in this codebase's CI
 * pipeline or deployment process ever runs `php artisan db:seed`, so any
 * canonical content that must exist in every environment automatically has
 * to ship as a migration.
 *
 * ---------------------------------------------------------------------------
 * `fulfillment_owner`, `requires_schedule`, `requires_manual_confirmation`
 * left at their table defaults (`NULL` / `false` / `false`) for all 12 rows
 * — a deliberate judgement call, not an oversight
 * ---------------------------------------------------------------------------
 * `service-catalog.md`'s "Catalog rules" section states these as CAPABILITIES
 * the platform must support ("Every service declares fulfillment owner...",
 * "Services requiring schedule expose a date/time window", "Sensitive
 * services may require manual confirmation") but never maps any of the 12
 * codes to a specific owner or flags either one `true`. Guessing — e.g.
 * assuming `AMBULANCE` is vendor-fulfilled, or that `GRAVE_DIGGING` requires
 * manual confirmation — would be exactly the fabricated business data this
 * batch's brief forbids ("Do not invent business data beyond the codes
 * actually catalogued and whatever the spec's design.md explicitly calls
 * for"). These three
 * columns exist so an admin can set them per `service-catalog.md`'s own
 * "Admin manages ... provider ... without deployment" rule, in a later
 * batch's admin surface — not so this migration can invent the values on
 * their behalf. FLAGGED FOR THE NEXT READER: this means, immediately after
 * this migration runs, no seeded service has a fulfillment owner recorded
 * yet — that is expected, not a bug, until an admin (or a follow-up
 * decision in `docs/product/service-catalog.md` itself) sets one per code.
 *
 * `is_active` seeds `true` for all 12 — every catalogued code is live by
 * default; `description` seeds `null` — `service-catalog.md` gives no
 * description copy beyond the code/label pair, only the label.
 *
 * Migration timestamp slot: see
 * `2026_07_26_180000_create_service_definitions_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Shape: [code, Indonesian label, category] — service-catalog.md's
        // own table order, basic table first then additional table, each in
        // that table's own row order.
        $services = [
            // --- Basic services ---
            [ServiceCode::DOCUMENT_PROCESSING, 'Pengurusan Dokumen', ServiceCategory::BASIC],
            [ServiceCode::GRAVE_DIGGING, 'Penggalian Makam', ServiceCategory::BASIC],

            // --- Additional services ---
            [ServiceCode::AMBULANCE, 'Ambulans', ServiceCategory::ADDITIONAL],
            [ServiceCode::FUNERAL_HOME, 'Rumah Duka', ServiceCategory::ADDITIONAL],
            [ServiceCode::HEARSE, 'Mobil Jenazah', ServiceCategory::ADDITIONAL],
            [ServiceCode::TENT_AND_CHAIRS, 'Tenda & Kursi', ServiceCategory::ADDITIONAL],
            [ServiceCode::SOUND_SYSTEM, 'Sound System', ServiceCategory::ADDITIONAL],
            [ServiceCode::FLOWERS, 'Karangan Bunga', ServiceCategory::ADDITIONAL],
            [ServiceCode::GRAVESTONE, 'Batu Nisan', ServiceCategory::ADDITIONAL],
            [ServiceCode::DOCUMENTATION, 'Dokumentasi', ServiceCategory::ADDITIONAL],
            [ServiceCode::CATERING, 'Konsumsi', ServiceCategory::ADDITIONAL],
            [ServiceCode::LIVE_STREAMING, 'Live Streaming', ServiceCategory::ADDITIONAL],
        ];

        foreach ($services as [$code, $name, $category]) {
            DB::table('service_definitions')->insert([
                'code' => $code,
                'name' => $name,
                'category' => $category,
                'fulfillment_owner' => null,
                'requires_schedule' => false,
                'requires_manual_confirmation' => false,
                'is_active' => true,
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('service_definitions')->whereIn('code', ServiceCode::KNOWN_CODES)->delete();
    }
};
