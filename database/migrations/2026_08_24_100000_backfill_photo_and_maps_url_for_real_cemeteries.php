<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills `primary_photo_path` for the 4 REAL, named cemeteries currently
 * live on `dev`/`stg`/`beta` (`tpu-karet-bivak`, `tpu-petamburan`,
 * `tpu-semper-budi-dharma`, `tpu-pondok-kelapa`) and `google_maps_url` for
 * one of them — NOT the fictional example dataset
 * `2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php`
 * targets (`App\Support\ExampleData\CemeteryExampleData`'s ten `tpu-jakarta-1`
 * -style placeholder rows). These are different rows entirely — this
 * migration is additive and does not touch that one's targets.
 *
 * ---------------------------------------------------------------------------
 * Photos — the same 4 generic SVG illustrations that migration already
 * shipped at `public/images/cemeteries/illustration-0{1,2,3,4}-*.svg`,
 * reused here for a different reason
 * ---------------------------------------------------------------------------
 * These are REAL, named, identifiable public cemeteries (TPU Karet Bivak,
 * Petamburan, Pondok Kelapa, Semper/Budi Dharma are genuine DKI Jakarta
 * government-operated TPUs). A photo search would risk misattributing a
 * photo of a DIFFERENT real cemetery to the wrong one — a real accuracy
 * problem on a live public site, not just a licensing question. The existing
 * illustrations are deliberately abstract (a generic gate/grove/path/garden
 * motif, no specific place depicted, no religious iconography), so reusing
 * them here makes no claim about what any specific cemetery actually looks
 * like — the same property that made them safe for the fictional dataset
 * applies equally well here, for a different reason (misattribution risk
 * instead of fabrication risk). Assigned round-robin by row order for visual
 * variety across the public directory listing, not because any specific
 * illustration means anything about a specific cemetery.
 *
 * ---------------------------------------------------------------------------
 * Map — one cemetery gets a verified real address, one is deliberately left
 * alone, two already had real coordinates before this migration
 * ---------------------------------------------------------------------------
 * `tpu-karet-bivak` and `tpu-semper-budi-dharma` already carry real,
 * survey-precision (7-decimal) coordinates and a coordinate-derived
 * `google_maps_url` — set by an earlier, separate process, not touched here.
 *
 * `tpu-petamburan` gets a NEW `google_maps_url`, built from its real,
 * cross-referenced street address (Jl. Aipda K.S. Tubun No. 1, Kel.
 * Petamburan, Kec. Tanah Abang, Jakarta Pusat — confirmed against the DKI
 * Jakarta parks/cemetery department's own site,
 * pertamananpemakaman.jakarta.go.id, and independently corroborated by
 * multiple funeral-service directories), via the same
 * `/maps/search/?api=1&query=...` pattern `Cemetery::googleMapsUrl()`
 * already uses for coordinate-derived links — this is a real, honest,
 * address-based search link, not a fabricated coordinate pin. No precise
 * lat/long was found for this cemetery from any reliable source, so none is
 * set — `latitude`/`longitude` stay `null`, consistent with this project's
 * standing anti-fabrication discipline (do not invent a coordinate that
 * reads as more precise than what was actually verified).
 *
 * `tpu-pondok-kelapa` is deliberately left untouched by this migration's map
 * fields (photo still applies). One aggregator (dilokasi.com) tags it
 * "Permanently Closed" while several other sources (a funeral-service
 * provider's own site, other directories, a listed working phone number)
 * describe it as active — a real enough discrepancy that this migration
 * does not add a map presence for it until a human confirms current
 * operating status. Its real, cross-referenced address (Jl. Haji Naman, Kel.
 * Pondok Kelapa, Kec. Duren Sawit, Jakarta Timur 13450) is recorded here in
 * this comment for whoever verifies it next, not written to any column.
 */
return new class extends Migration
{
    private const string PETAMBURAN_MAPS_URL = 'https://www.google.com/maps/search/?api=1&query=Jl.+Aipda+K.S.+Tubun+No.+1%2C+Kel.+Petamburan%2C+Kec.+Tanah+Abang%2C+Jakarta+Pusat%2C+Indonesia';

    private const array PHOTOS = [
        'images/cemeteries/illustration-01-gate.svg',
        'images/cemeteries/illustration-02-grove.svg',
        'images/cemeteries/illustration-03-path.svg',
        'images/cemeteries/illustration-04-garden.svg',
    ];

    private const array SLUGS_IN_ORDER = [
        'tpu-karet-bivak',
        'tpu-petamburan',
        'tpu-pondok-kelapa',
        'tpu-semper-budi-dharma',
    ];

    public function up(): void
    {
        foreach (self::SLUGS_IN_ORDER as $index => $slug) {
            DB::table('cemeteries')
                ->where('slug', $slug)
                ->update([
                    'primary_photo_path' => self::PHOTOS[$index % count(self::PHOTOS)],
                    'updated_at' => now(),
                ]);
        }

        DB::table('cemeteries')
            ->where('slug', 'tpu-petamburan')
            ->whereNull('google_maps_url')
            ->update([
                'google_maps_url' => self::PETAMBURAN_MAPS_URL,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('cemeteries')
            ->whereIn('slug', self::SLUGS_IN_ORDER)
            ->update([
                'primary_photo_path' => null,
                'updated_at' => now(),
            ]);

        DB::table('cemeteries')
            ->where('slug', 'tpu-petamburan')
            ->where('google_maps_url', self::PETAMBURAN_MAPS_URL)
            ->update([
                'google_maps_url' => null,
                'updated_at' => now(),
            ]);
    }
};
